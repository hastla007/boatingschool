<?php

namespace App\Providers;

use App\Auth\TenantSessionGuard;
use App\Support\CanonicalUrl;
use App\Support\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Client\Factory;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\NotPwnedVerifier;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Mime\Address;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        // Passwort-Leak-Prüfung (api.pwnedpasswords.com): höchstens 5 s statt
        // 30 s warten, wenn der Dienst nicht erreichbar ist (die Prüfung
        // lässt das Passwort dann ohnehin zu).
        // (extend statt singleton: der ValidationServiceProvider ist deferred
        // und würde eine eigene Bindung beim ersten Gebrauch überschreiben.)
        $this->app->extend(UncompromisedVerifier::class,
            fn ($verifier, $app) => new NotPwnedVerifier($app[Factory::class], 5));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Eigenes Remember-Cookie je Schule im Pfad-Modus (siehe TenantSessionGuard).
        Auth::extend('session', function ($app, string $name, array $config) {
            $guard = new TenantSessionGuard(
                $name,
                Auth::createUserProvider($config['provider'] ?? null),
                $app['session.store'],
                rehashOnLogin: $app['config']->get('hashing.rehash_on_login', true),
                timeboxDuration: $app['config']->get('auth.timebox_duration', 200000),
                hashKey: $app['config']->get('app.key'),
            );
            $guard->setCookieJar($app['cookie']);
            $guard->setDispatcher($app['events']);
            $guard->setRequest($app->refresh('request', $guard, 'setRequest'));
            if (isset($config['remember'])) {
                $guard->setRememberDuration($config['remember']);
            }

            return $guard;
        });

        // Die Verifizierungs-Mail nach der Registrierung verdrahtet Laravel 12
        // selbst (EventServiceProvider::configureEmailVerification); ein
        // eigenes Event::listen() hier schickte sie doppelt.

        $this->registerUuidRoutePatterns();
        $this->configurePasswordPolicy();
        $this->configurePasswordResetUrl();
        $this->configureVerifyEmailUrl();
        $this->configureHealthCheck();
    }

    /**
     * Bug-Review Betrieb: Laravels eingebauter /up-Healthcheck (siehe
     * bootstrap/app.php, health: '/up') feuert lediglich das
     * DiagnosingHealth-Event und liefert 200, solange kein Listener eine
     * Exception wirft -- ohne eigenen Listener prüft er also NUR, ob PHP
     * selbst noch Requests annimmt, nicht, ob die App tatsächlich
     * funktioniert. Eine abgeschaltete/unerreichbare Datenbank lieferte
     * damit weiterhin 200, obwohl praktisch jeder echte Request (jede
     * Seite mit RLS-Query) mit 500 gescheitert wäre -- ein Monitoring/
     * Load-Balancer-Check auf /up hätte den Ausfall nie bemerkt.
     */
    private function configureHealthCheck(): void
    {
        // Review-Runde 9: Antworten auf Mails einer Schule (Einladung,
        // Terminvorschlag, Freischaltung …) gehen an deren bestätigte
        // Support-Adresse statt an die Absenderadresse der Plattform.
        Event::listen(MessageSending::class, function ($event) {
            $tenant = app(TenantContext::class)->tenant() ?? (app()->bound('currentTenant') ? app('currentTenant') : null);
            $branding = $tenant?->branding;
            if ($branding?->support_email && $branding->support_email_verified_at && $event->message->getReplyTo() === []) {
                $event->message->replyTo(new Address($branding->support_email, $tenant->name));
            }
        });

        Event::listen(DiagnosingHealth::class, function () {
            DB::connection()->getPdo();
        });
    }

    /**
     * Sicherheitsreview S1: ohne eigenen createUrlCallback baut
     * ResetPassword::resetUrl() die URL über url(route(..., false)) --
     * route(..., false) liefert nur den relativen Pfad, url() löst die
     * fehlende Domain über Illuminate\Routing\UrlGenerator::to() aus
     * $this->request->root() auf, also aus dem HOST-HEADER DES REQUESTS,
     * der gerade /forgot-password verarbeitet (forceRootUrl() wird
     * nirgends im Projekt aufgerufen). ResolveTenant::isTenantAgnosticRoute()
     * erlaubt /forgot-password aber bewusst auf JEDEM vertrauten Host --
     * auch der Custom Domain einer beliebigen Bootsschule -- unabhängig
     * davon, wem das per E-Mail angegebene Konto gehört
     * (Password::sendResetLink() ist rollenunabhängig, betrifft also auch
     * Superadmins). Wer später die DNS dieser Domain kontrolliert (z. B.
     * eine Custom Domain, die eine Schule nicht mehr betreibt), kontrolliert
     * damit jeden noch gültigen, bereits verschickten Reset-Link und kann
     * das jeweilige Konto übernehmen. Die URL wird deshalb unabhängig vom
     * Host des auslösenden Requests immer explizit auf die zentrale Domain
     * verankert; der relative Pfad kommt weiterhin aus route(..., false),
     * damit er mit der tatsächlichen Routendefinition (routes/auth.php)
     * übereinstimmt.
     */
    /**
     * Sicherheitsreview S1 (Rest): der Bestätigungslink der E-Mail-Adresse
     * hing wie früher der Reset-Link vom Host des Requests ab
     * (email/verification-notification ist tenant-agnostisch, also auch
     * über jede Custom Domain erreichbar). Er wird deshalb auf der
     * Subdomain der Schule bzw. der zentralen Domain erzeugt.
     */
    private function configureVerifyEmailUrl(): void
    {
        VerifyEmail::createUrlUsing(fn ($notifiable) => CanonicalUrl::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(config('auth.verification.expire', 60)),
            ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())],
            // Review-Runde 4: auf der Subdomain der Schule, auf der sich die
            // Person registriert hat und angemeldet ist (das Sitzungs-Cookie
            // gilt nur dort) -- nur die Custom Domain wird vermieden.
            app()->bound('currentTenant') ? app('currentTenant') : null,
        ));
    }

    private function configurePasswordResetUrl(): void
    {
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            // In einer Schule angefordert: Link unter der Adresse der Schule
            // (Pfad-Modus /<slug>/, Port lokal), sonst zentral.
            // „Passwort vergessen“ ist mandantenunabhängig: dort setzt
            // ResolveTenant nur currentTenant, nicht den TenantContext.
            $tenant = app(TenantContext::class)->tenant() ?? (app()->bound('currentTenant') ? app('currentTenant') : null);

            return CanonicalUrl::root($tenant).route('password.reset', ['token' => $token], false).'?'.Arr::query([
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);
        });
    }

    /**
     * Sicherheitsreview N3: ohne eigene Password::defaults() griff nur
     * Laravels eingebauter Minimalstandard (8 Zeichen, sonst ungeprüft).
     * uncompromised() ruft die HIBP-API live auf -- in local/testing bewusst
     * ausgelassen, damit Tests (u. a. Registrierung/Passwort-Reset) nicht von
     * einem externen Netzwerkaufruf abhängen.
     */
    private function configurePasswordPolicy(): void
    {
        Password::defaults(function () {
            $rule = Password::min(10);

            return app()->environment('local', 'testing') ? $rule : $rule->uncompromised();
        });
    }

    /**
     * Ein nicht-UUID-Wert für einen UUID-typisierten Routenparameter (z. B.
     * {course}) scheitert sonst erst beim Postgres-UUID-Cast der
     * Route-Model-Binding-Query -- eine harte 500-Exception statt eines
     * sauberen 404 (Sicherheitsreview M4). Zentral für alle UUID-
     * Routenparameter registriert statt verstreut per Route.
     */
    private function registerUuidRoutePatterns(): void
    {
        $uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

        foreach ([
            'course', 'coupon', 'coursePromoBanner', 'entitlement', 'examAppointment',
            'examSession', 'favoriteCollection', 'invitation', 'lesson', 'module',
            'paper', 'praxisFormTemplate', 'product', 'pruefungsausschuss', 'question',
            'submission', 'task', 'tenantCourseLocation', 'tenant', 'user',
            'willkommensboxOrder', 'addonTemplate', 'id', 'tip', 'tipCategory',
        ] as $parameter) {
            Route::pattern($parameter, $uuid);
        }
    }
}
