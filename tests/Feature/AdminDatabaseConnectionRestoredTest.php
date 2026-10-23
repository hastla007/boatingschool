<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review M6 (Folgefehler): UseAdminDatabaseConnection setzte
 * database.default bisher ohne try/finally auf die RLS-umgehende
 * Owner-Verbindung "pgsql_admin" und ließ es dort stehen. In klassischem
 * PHP-FPM (ein Request pro Prozess-Bootstrap) ist das folgenlos, aber
 * sobald der PHP-Prozess für einen WEITEREN Request wiederverwendet wird
 * (Laravel Octane/Swoole/RoadRunner halten die Anwendung über mehrere
 * Requests hinweg im selben Worker am Leben), bliebe JEDER folgende
 * Request -- auch ein ganz normaler Lerner-Request ohne jeden
 * Superadmin-Bezug -- auf der Owner-Verbindung und würde RLS vollständig
 * umgehen, bis der Worker neu startet.
 *
 * PHPUnits Feature-Test-Client simuliert genau dieses Szenario: mehrere
 * ->get()/->post()-Aufrufe innerhalb EINES Testlaufs teilen sich dieselbe,
 * bereits gebootete $app-Instanz -- ein config()-Leck aus einem früheren
 * Request bliebe hier sichtbar, exakt wie unter Octane.
 */
class AdminDatabaseConnectionRestoredTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_the_default_connection_is_restored_after_a_superadmin_request(): void
    {
        $originalDefault = config('database.default');
        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)->get('/superadmin')->assertOk();

        $this->assertSame($originalDefault, config('database.default'));
    }

    /**
     * Dieselbe Sache, aber über den tatsächlichen Risikofall geprüft statt
     * nur über den internen Config-Wert: ohne Zurücksetzen würde jede
     * direkte DB-Abfrage NACH einem Superadmin-Request (hier via
     * DB::connection() ohne expliziten Namen, genau wie der allergrößte
     * Teil der Anwendung sie stellt) weiterhin über die RLS-umgehende
     * Owner-Verbindung laufen und dadurch Zeilen JEDER Bootsschule sehen,
     * nicht nur die der aktuell aufgelösten.
     */
    public function test_a_plain_query_after_a_superadmin_request_does_not_bypass_rls(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $this->createTenantUser($tenantA, 'learner');
        $this->createTenantUser($tenantB, 'learner');

        $this->actingAs($superadmin)->get('/superadmin')->assertOk();

        $this->asTenant($tenantA, function () use ($tenantA) {
            $visibleTenantIds = \Illuminate\Support\Facades\DB::table('tenant_user')->distinct()->pluck('tenant_id')->all();

            $this->assertSame([$tenantA->id], $visibleTenantIds);
        });
    }
}
