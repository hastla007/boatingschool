<?php

namespace App\Providers;

use App\Support\TenantContext;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Verschickt die Verifizierungs-Mail nach der Registrierung (User
        // implementiert MustVerifyEmail, siehe Sicherheitsreview H2) -- in
        // Laravel 11 ohne EventServiceProvider muss das explizit verdrahtet
        // werden.
        Event::listen(Registered::class, SendEmailVerificationNotification::class);

        $this->registerUuidRoutePatterns();
        $this->configurePasswordPolicy();
        $this->configurePasswordResetUrl();
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
    private function configurePasswordResetUrl(): void
    {
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $scheme = app()->environment('production') ? 'https' : 'http';

            return $scheme.'://'.config('app.central_domain').route('password.reset', ['token' => $token], false).'?'.Arr::query([
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
