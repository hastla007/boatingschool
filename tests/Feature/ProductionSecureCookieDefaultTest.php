<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Bug-Review M9 (Folgefehler): ohne gesetztes SESSION_SECURE_COOKIE fiel
 * config/session.php bisher auf null (automatisch, je nach aktuellem
 * Request-Schema) zurück -- eine produktive Umgebung, die diese
 * .env-Variable vergisst, bekam also keinen erzwungenen Secure-Flag auf dem
 * Session-Cookie.
 *
 * APP_ENV wird hier VOR dem App-Bootstrap gesetzt (setUp() vor
 * parent::setUp()), weil config/session.php beim Hochfahren der Anwendung
 * einmalig ausgewertet wird -- ein Setzen im Testkörper selbst käme zu
 * spät (dieselbe Einschränkung wie bei TrustedProxyDevOverrideTest).
 */
class ProductionSecureCookieDefaultTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';
        putenv('SESSION_SECURE_COOKIE');
        unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';

        parent::tearDown();
    }

    public function test_the_session_cookie_is_forced_secure_in_production_even_without_the_env_variable(): void
    {
        $this->assertTrue(config('session.secure'));
    }

    /**
     * Pinnt den tatsächlichen Default-Wert in config/session.php fest --
     * der Test oben allein prüft nur das Laufzeitverhalten, nicht ob genau
     * DIESER Code-Default (statt z. B. eines harten "true", das eine
     * bewusste Abschaltung per .env verhindern würde) tatsächlich verwendet
     * wird.
     */
    public function test_session_secure_config_default_literal_still_lets_env_override_it(): void
    {
        $configSource = file_get_contents(config_path('session.php'));

        $this->assertMatchesRegularExpression(
            "/env\\('SESSION_SECURE_COOKIE',\\s*env\\('APP_ENV'\\) === 'production'\\)/",
            $configSource,
            "config/session.php muss env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production') verwenden -- ein hartes true ohne env()-Override würde eine bewusste Abschaltung in einer Nicht-Produktivumgebung verhindern."
        );
    }
}
