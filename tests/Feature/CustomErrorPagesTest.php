<?php

namespace Tests\Feature;

use Illuminate\Foundation\Exceptions\RegisterErrorViewPaths;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "Keine Fehlerseiten": resources/views/errors/ existierte bislang gar
 * nicht -- 404/403/419/429/500/503 fielen auf die generischen, englischen
 * Standardseiten von illuminate/foundation zurück (kein Branding, kein
 * Link zurück, keine deutsche Sprache). Die neuen Views sind bewusst
 * vollständig eigenständig (siehe errors/minimal.blade.php) und dürfen
 * selbst dann nicht crashen, wenn genau das fehlgeschlagen ist, was eine
 * normale Seite voraussetzt (kein Mandant, keine DB, abgelaufene Session).
 */
class CustomErrorPagesTest extends TestCase
{
    public static function statusCodeProvider(): array
    {
        return [
            ['404', 'Seite nicht gefunden'],
            ['403', 'Zugriff verweigert'],
            ['419', 'Sitzung abgelaufen'],
            ['429', 'Zu viele Anfragen'],
            ['500', 'Etwas ist schiefgelaufen'],
            ['503', 'Kurze Wartungspause'],
        ];
    }

    #[DataProvider('statusCodeProvider')]
    public function test_each_custom_error_view_renders_standalone_without_any_tenant_or_auth_context(string $code, string $expectedMessage): void
    {
        // Die view()-Namespace-Zuordnung "errors::" (die @extends('errors::
        // minimal') in jeder dieser Views braucht) registriert Laravel erst
        // lazy innerhalb des Exception-Handlers -- hier manuell nachgeholt,
        // um die Views auch isoliert ohne eine echte Exception zu prüfen.
        (new RegisterErrorViewPaths)();

        $html = view("errors.{$code}")->render();

        $this->assertStringContainsString($expectedMessage, $html);
        $this->assertStringContainsString($code, $html);
        $this->assertStringContainsString('Zur Startseite', $html);
    }

    public function test_an_unknown_route_renders_the_custom_german_404_page_not_the_generic_default(): void
    {
        $response = $this->get('/diese-route-gibt-es-nicht-xyz');

        $response->assertNotFound();
        $response->assertSee('Seite nicht gefunden');
    }

    public function test_maintenance_mode_renders_the_custom_german_503_page_not_the_generic_default(): void
    {
        Artisan::call('down');

        try {
            $response = $this->get('/');

            $response->assertStatus(503);
            $response->assertSee('Kurze Wartungspause');
        } finally {
            Artisan::call('up');
        }
    }
}
