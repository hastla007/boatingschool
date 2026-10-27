<?php

namespace Tests\Unit;

use App\Support\AppRoleGrants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Bug-Review Betrieb B4: GRANT/REVOKE-Anweisungen in den Migrationen prüften
 * bislang gegen einen in jeder Migration fest eingetragenen Rollennamen
 * ('boatingschool_app'), statt die tatsächlich konfigurierte Rolle
 * (config('database.connections.pgsql.username'), DB_USERNAME) zu verwenden
 * -- und übersprangen den GRANT/REVOKE kommentarlos, sobald beide
 * voneinander abwichen. AppRoleGrants behebt beides: role() liest die echte
 * Konfiguration statt einen Namen zu wiederholen, roleExistsOrWarn()
 * protokolliert eine fehlende Rolle sichtbar statt sie stillschweigend zu
 * übergehen.
 */
class AppRoleGrantsTest extends TestCase
{
    public function test_role_reads_the_actually_configured_username_not_a_hardcoded_literal(): void
    {
        config(['database.connections.pgsql.username' => 'ein_ganz_anderer_rollenname']);

        $this->assertSame('ein_ganz_anderer_rollenname', AppRoleGrants::role());
    }

    public function test_role_exists_or_warn_returns_true_for_the_real_configured_app_role(): void
    {
        $this->assertTrue(AppRoleGrants::roleExistsOrWarn());
    }

    public function test_role_exists_or_warn_logs_a_visible_warning_instead_of_silently_skipping(): void
    {
        // Stellt die echte PDO-Verbindung VOR der config()-Änderung her,
        // damit die anschließende Prüfung weiterhin über die echte Rolle
        // läuft (wie beim echten Migrationslauf über pgsql_admin) und nicht
        // versucht, sich mit dem erfundenen, nicht existierenden Namen neu
        // zu verbinden (das würde nur an den Zugangsdaten scheitern, nicht
        // an der hier zu testenden Logik).
        DB::connection()->getPdo();

        config(['database.connections.pgsql.username' => 'definitiv_keine_existierende_rolle_xyz']);

        Log::shouldReceive('warning')
            ->once()
            ->with($this->stringContains('definitiv_keine_existierende_rolle_xyz'));

        $this->assertFalse(AppRoleGrants::roleExistsOrWarn());
    }
}
