<?php

namespace App\Providers;

use App\Api\CourseAccess;
use App\Auth\TenantSessionGuard;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\CanonicalUrl;
use App\Support\DisabledCourses;
use App\Support\LoginActivity;
use App\Support\MailSchool;
use App\Support\ProductionReadiness;
use App\Support\RegistrationCouponNotice;
use App\Support\SchoolMails;
use App\Support\TenantContext;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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
        // Audit ARC-5: je Request gemerkt (WeakMap auf den Request).
        $this->app->singleton(DisabledCourses::class);
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
        // Mobile-API: der Guard bekommt seinen Nutzer von AuthenticateMobileApi.
        Auth::viaRequest('mobile-token', fn () => null);
        // Mobile-API: Erneuern je Refresh-Token (10/Min.) und großzügig je IP
        // (viele Geräte hinter einem Schul- oder Mobilfunk-Netz).
        RateLimiter::for('api-refresh', fn (Request $request) => [
            Limit::perMinute(10)->by('api-refresh-token:'.hash('sha256', (string) $request->input('refresh_token'))),
            Limit::perMinute(300)->by('api-refresh-ip:'.$request->ip()),
        ]);
        $this->configureCommerceRateLimits();

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

        // Ergebnis des Zugangscodes aus der Registrierung nach der Anmeldung zeigen.
        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User) {
                RegistrationCouponNotice::moveToSession($event->user);
                // Letzte Anmeldung für die Löschung inaktiver Konten -- nicht,
                // wenn der Support ein Konto ansieht (das ist keine Anmeldung
                // der Person selbst).
                if (! request()->routeIs('impersonate.consume')) {
                    LoginActivity::record($event->user->id);
                }
            }
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
     * Commerce-API (routes/commerce.php): getrennte Töpfe je Shop. Konto- und
     * Statusabfragen lösen Shopkunden indirekt aus (Warenkorb, „Mein Konto“);
     * sie dürfen Lieferungen, Sperren und Batch-Abfragen (idempotent, vom
     * Shop-Server geplant) nie ausbremsen. Zusätzlich je Shopkunde begrenzt,
     * damit ein einzelner Kunde nicht den ganzen Konto-Topf leert. Die
     * Signatur ist vorher geprüft (commerce_shop_id gesetzt).
     */
    private function configureCommerceRateLimits(): void
    {
        $shop = fn (Request $request): string => (string) ($request->attributes->get('commerce_shop_id') ?: $request->ip());

        RateLimiter::for('commerce-orders', fn (Request $request) => Limit::perMinute((int) config('commerce.rate_limits.orders', 1200))
            ->by('commerce-orders:'.$shop($request)));

        RateLimiter::for('commerce-accounts', function (Request $request) use ($shop) {
            $limits = [];
            // Kunde aus dem Pfad (/accounts/{customerId}/…) oder bei der Registrierung
            // aus dem Body. Zuerst geprüft: ThrottleRequests zählt eine Anfrage in
            // allen Töpfen bis zu dem, der sie abweist -- ein gedrosselter Kunde
            // verbraucht so nichts vom gemeinsamen Topf.
            $customer = $request->route('customerId') ?? $request->input('shop_customer_id');
            if (is_string($customer) && $customer !== '') {
                $limits[] = Limit::perMinute((int) config('commerce.rate_limits.per_customer', 20))
                    ->by('commerce-customer:'.$shop($request).':'.hash('sha256', $customer));
            }
            $limits[] = Limit::perMinute((int) config('commerce.rate_limits.accounts', 300))->by('commerce-accounts:'.$shop($request));

            return $limits;
        });
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
        // Review-Runde 10: nur für Mails, die die Schule an ihre Teilnehmer
        // schickt (Liste unten) -- nie für Konto- und Sicherheitsmails der
        // Plattform (Passwort, Bestätigung, Registrierungsversuch …), sonst
        // könnte eine Schule dort ihre eigene Antwortadresse unterbringen.
        // Liste in SchoolMails (auch für die Schlusszeile ohne Antwortadresse).
        $schoolMails = SchoolMails::NOTIFICATIONS;
        // Review-Runde 12: Absendername wie die Unterschrift -- die Schule, aus
        // deren Bereich die Mail kommt, sonst der Plattformname (statt APP_NAME).
        Event::listen(MessageSending::class, function ($event) {
            $from = $event->message->getFrom()[0] ?? null;
            if ($from) {
                $name = MailSchool::current()?->name ?? PlatformSetting::siteName();
                $event->message->from(new Address($from->getAddress(), $name));
            }
        });

        Event::listen(MessageSending::class, function ($event) use ($schoolMails) {
            if (! in_array($event->data['__laravel_notification'] ?? null, $schoolMails, true)) {
                return;
            }
            $tenant = MailSchool::current();
            if (SchoolMails::hasVerifiedReplyTo() && $event->message->getReplyTo() === []) {
                $event->message->replyTo(new Address($tenant->branding->support_email, $tenant->name));
            }
        });

        // Audit ARC-V2: Migrationen ändern teils Inhalte (Korrekturen an Fragen,
        // Medien) -- danach Kurs-Zwischenspeicher der App neu berechnen lassen.
        Event::listen(MigrationsEnded::class, fn () => rescue(fn () => CourseAccess::bumpContentVersion(), report: false));

        // Audit ARC-2: Lebenszeichen des Queue-Workers für app:check-production.
        Event::listen(Looping::class, fn () => ProductionReadiness::recordWorkerHeartbeat());

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
        // Review-Runde 12: Team-Mitglieder und Plattformbetrieb werden gesiezt.
        ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $formal = method_exists($notifiable, 'prefersFormalAddress') && $notifiable->prefersFormalAddress();
            $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

            return (new MailMessage)
                ->subject('Passwort zurücksetzen')
                ->line($formal
                    ? 'Sie erhalten diese E-Mail, weil für Ihr Konto ein neues Passwort angefordert wurde.'
                    : 'Du erhältst diese E-Mail, weil für dein Konto ein neues Passwort angefordert wurde.')
                ->action('Passwort zurücksetzen', call_user_func(ResetPassword::$createUrlCallback, $notifiable, $token))
                ->line("Der Link ist {$minutes} Minuten gültig.")
                ->line($formal
                    ? 'Falls Sie kein neues Passwort angefordert haben, müssen Sie nichts weiter tun.'
                    : 'Falls du kein neues Passwort angefordert hast, musst du nichts weiter tun.');
        });

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
