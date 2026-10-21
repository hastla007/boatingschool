<?php

namespace App\Providers;

use App\Support\TenantContext;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
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
