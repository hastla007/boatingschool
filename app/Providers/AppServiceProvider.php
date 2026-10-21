<?php

namespace App\Providers;

use App\Support\TenantContext;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

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
    }
}
