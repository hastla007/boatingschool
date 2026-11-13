<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * TLS zur Datenbank: sslmode beider Postgres-Verbindungen (App-Rolle und
 * Owner-Rolle) kommt aus DB_SSLMODE, Standard "prefer" (lokal ohne
 * Zertifikat lauffähig). Produktion mit entfernter Datenbank: verify-full.
 */
class DatabaseSslModeConfigTest extends TestCase
{
    private function loadConfigWith(?string $value): array
    {
        $previous = getenv('DB_SSLMODE');
        $value === null ? putenv('DB_SSLMODE') : putenv("DB_SSLMODE={$value}");
        unset($_ENV['DB_SSLMODE'], $_SERVER['DB_SSLMODE']);
        if ($value !== null) {
            $_ENV['DB_SSLMODE'] = $_SERVER['DB_SSLMODE'] = $value;
        }

        try {
            return require config_path('database.php');
        } finally {
            $previous === false ? putenv('DB_SSLMODE') : putenv("DB_SSLMODE={$previous}");
            unset($_ENV['DB_SSLMODE'], $_SERVER['DB_SSLMODE']);
        }
    }

    public function test_sslmode_defaults_to_prefer_for_both_connections(): void
    {
        $config = $this->loadConfigWith(null);

        $this->assertSame('prefer', $config['connections']['pgsql']['sslmode']);
        $this->assertSame('prefer', $config['connections']['pgsql_admin']['sslmode']);
    }

    public function test_sslmode_follows_db_sslmode_for_both_connections(): void
    {
        $config = $this->loadConfigWith('verify-full');

        $this->assertSame('verify-full', $config['connections']['pgsql']['sslmode']);
        $this->assertSame('verify-full', $config['connections']['pgsql_admin']['sslmode']);
    }

    public function test_env_example_and_deploy_guide_document_db_sslmode(): void
    {
        $this->assertStringContainsString('DB_SSLMODE=prefer', file_get_contents(base_path('.env.example')));
        $this->assertStringContainsString('verify-full', file_get_contents(base_path('.env.example')));
        $this->assertStringContainsString('DB_SSLMODE=verify-full', file_get_contents(base_path('deploy/README.md')));
    }
}
