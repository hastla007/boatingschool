<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Bug-Review (Hoch): env('TRUSTED_PROXIES', '') stand bisher DIREKT in
 * bootstrap/app.php, außerhalb jeder config/*.php-Datei. Nach dem in
 * Produktion üblichen `php artisan config:cache` (Teil von `php artisan
 * optimize`) überspringt Laravel das Laden der .env-Datei komplett --
 * env() außerhalb einer gecachten Config liefert dann IMMER den
 * übergebenen Default, unabhängig vom tatsächlichen Wert in der .env.
 * TRUSTED_PROXIES hätte sich dadurch nach jedem optimize unbemerkt selbst
 * auf "kein Proxy vertrauenswürdig" zurückgesetzt: kein HSTS mehr (siehe
 * SecurityHeaders) und $request->ip() liefert für JEDEN Nutzer hinter dem
 * Reverse-Proxy dieselbe Proxy-Adresse -- die IP-basierten throttle-Limits
 * (routes/auth.php) teilen sich damit plattformweit EINEN Zähler, zehn
 * Fehlversuche eines beliebigen Angreifers sperren den Login für alle
 * anderen Nutzer hinter demselben Proxy (Login-DoS).
 *
 * Derselbe Fund traf auch App\Console\Commands\CreateSuperAdmin::
 * resolvePassword() (env('SUPERADMIN_PASSWORD')): dieselbe
 * LoadEnvironmentVariables-Bootstrapper-Logik gilt für JEDEN Artisan-
 * Prozess, nicht nur für HTTP-Requests -- ein späterer, automatisierter/
 * skriptgesteuerter app:create-superadmin-Aufruf (Docker-Entrypoint, CI,
 * Ansible) hätte die in der .env gesetzte Variable nach einem config:cache
 * nie mehr gesehen und wäre lautlos auf den interaktiven secret()-Prompt
 * zurückgefallen, der ohne TTY nie etwas liefert.
 *
 * Beide Stellen lesen den Wert jetzt über config('app.*') statt per
 * direktem env()-Aufruf -- env() innerhalb von config/app.php selbst
 * bleibt sicher, weil genau dieser Aufruf beim config:cache-Lauf aufgelöst
 * und mit seinem tatsächlichen Wert in die gecachte Config geschrieben
 * wird.
 */
class ConfigCacheSafeEnvUsageTest extends TestCase
{
    public function test_bootstrap_reads_trusted_proxies_from_config_not_directly_from_env(): void
    {
        $source = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString("config('app.trusted_proxies'", $source);
        $this->assertStringNotContainsString("env('TRUSTED_PROXIES'", $source);
    }

    public function test_create_superadmin_reads_the_password_from_config_not_directly_from_env(): void
    {
        $source = file_get_contents(base_path('app/Console/Commands/CreateSuperAdmin.php'));

        $this->assertStringContainsString("config('app.superadmin_password'", $source);
        $this->assertStringNotContainsString("env('SUPERADMIN_PASSWORD'", $source);
    }

    /**
     * Stellt sicher, dass die Umleitung über config() funktional nichts
     * ändert: der konfigurierte Wert muss weiterhin exakt die ENV-Variable
     * widerspiegeln, die env() innerhalb von config/app.php selbst liest.
     */
    public function test_trusted_proxies_config_value_reflects_the_env_variable(): void
    {
        $this->assertSame(env('TRUSTED_PROXIES', ''), config('app.trusted_proxies'));
    }
}
