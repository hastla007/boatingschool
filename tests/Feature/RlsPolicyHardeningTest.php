<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview M5: current_setting('app.current_tenant_id', true)
 * liefert nur dann NULL, wenn die GUC auf der Verbindung nie gesetzt wurde.
 * Ein vorgeschalteter Connection-Pooler im falschen Modus könnte eine
 * Verbindung aber auch mit einem leeren String übergeben -- ohne NULLIF
 * würde ''::uuid eine harte Exception werfen statt die Policy einfach
 * keine Zeilen zurückgeben zu lassen. Siehe auch README.md
 * ("Connection-Pooling und RLS") für die organisatorische Absicherung
 * (PgBouncer nur im Session-Modus).
 */
class RlsPolicyHardeningTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        DB::statement("SET app.current_tenant_id = '00000000-0000-0000-0000-000000000000'");

        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_an_empty_tenant_setting_fails_closed_instead_of_crashing(): void
    {
        $tenant = $this->createTestTenant();

        // Simuliert einen Pooler, der die Verbindung mit einer geleerten
        // statt einer ungesetzten GUC weitergibt.
        DB::statement("SET app.current_tenant_id = ''");

        $rows = DB::table('tenant_branding')->where('tenant_id', $tenant->id)->get();

        $this->assertCount(0, $rows);
    }

    public function test_an_empty_tenant_setting_never_exposes_another_tenants_rows(): void
    {
        $tenant = $this->createTestTenant();

        DB::statement("SET app.current_tenant_id = ''");

        $rows = DB::table('tenant_branding')->get();

        $this->assertCount(0, $rows);
    }
}
