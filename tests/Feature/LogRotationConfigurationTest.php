<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * "Keine Log-Rotation": LOG_STACK stand auf "single" -- storage/logs/
 * laravel.log wuchs dadurch unbegrenzt und ohne Rotation weiter, statt
 * (wie der im Grundgerüst bereits vorhandene "daily"-Kanal es anbietet)
 * nach LOG_DAILY_DAYS Tagen automatisch alte Dateien zu entfernen.
 */
class LogRotationConfigurationTest extends TestCase
{
    public function test_the_stack_channel_uses_the_rotating_daily_driver_not_the_unbounded_single_driver(): void
    {
        $this->assertSame('daily', config('logging.channels.stack.channels')[0] ?? null);
        $this->assertSame('daily', config('logging.channels.daily.driver'));
    }

    public function test_the_env_example_documents_the_daily_log_stack_as_the_default(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression(
            '/^LOG_STACK=daily$/m',
            $envExample,
            '.env.example muss LOG_STACK=daily als Default dokumentieren, damit eine frische Installation nicht wieder auf "single" (keine Rotation) zurückfällt.'
        );
    }

    /**
     * .env/.env.example allein reichen nicht: eine Umgebung, die LOG_STACK
     * nicht über eine .env-Datei setzt (echte Umgebungsvariablen, ein
     * vergessenes Deploy-Skript), fällt sonst auf den hartcodierten
     * Code-Default in config/logging.php zurück -- der muss deshalb selbst
     * schon "daily" sein, unabhängig davon, was eine .env-Datei im
     * aktuellen Testlauf zufällig enthält.
     */
    public function test_the_hardcoded_config_default_itself_is_daily_not_single(): void
    {
        $config = file_get_contents(base_path('config/logging.php'));

        $this->assertMatchesRegularExpression(
            "/env\\('LOG_STACK',\\s*'daily'\\)/",
            $config,
            'config/logging.php muss env(\'LOG_STACK\', \'daily\') als Code-Default nutzen, damit eine Umgebung ohne LOG_STACK in der .env nicht auf "single" zurückfällt.'
        );
    }
}
