<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview M7: app_user hatte bislang kein RLS, obwohl die Tabelle
 * inzwischen auch Geburtsdatum und Adresse enthält (siehe 000170/000434
 * Migrationen) -- jede Query der App-Rolle sah bisher jeden Nutzer jeder
 * Bootsschule, unabhängig vom aktuell aufgelösten Mandanten. Die neue
 * Policy (Migration 000461) lässt eine Zeile nur sichtbar, wenn kein
 * echter Tenant-Kontext gesetzt ist (Login/Registrierung/Passwort-Reset)
 * oder sie über tenant_user mit dem aktuellen Mandanten verknüpft ist.
 */
class AppUserRowLevelSecurityTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_a_user_outside_the_current_tenant_is_invisible_under_rls(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $userA = $this->createTenantUser($tenantA, 'learner');
        $userB = $this->createTenantUser($tenantB, 'learner');

        $visibleFromA = $this->asTenant($tenantA, fn () => User::find($userB->id));

        $this->assertNull($visibleFromA, 'Ein Nutzer einer fremden Bootsschule war unter RLS sichtbar.');
    }

    public function test_a_user_sharing_the_current_tenant_remains_visible(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $visible = $this->asTenant($tenant, fn () => User::find($user->id));

        $this->assertNotNull($visible);
        $this->assertSame($user->id, $visible->id);
    }

    public function test_the_admin_connection_still_bypasses_rls_entirely(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $userB = $this->createTenantUser($tenantB, 'learner');

        $visible = $this->asTenant($tenantA, fn () => $this->onAdmin(fn () => User::find($userB->id)));

        $this->assertNotNull($visible, 'Die Owner-Verbindung sollte RLS weiterhin umgehen.');
    }
}
