<?php

namespace Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview N10: admin-db (UseAdminDatabaseConnection) stand in der
 * Laravel-Standard-Priorität vor Authenticate und vor SubstituteBindings.
 * Dadurch lief die DB-Verbindung jedes Requests auf /superadmin/* -- auch
 * nicht angemeldeter oder nicht berechtigter -- schon vor dem eigentlichen
 * Zugriffsschutz auf die Owner-Verbindung um, mit dem Risiko eines
 * 404/403-Orakels über Route-Model-Binding auf RLS-freien Daten.
 */
class SuperadminMiddlewareOrderingTest extends TestCase
{
    use InteractsWithTenants;

    public function test_ensure_super_admin_runs_after_authentication_and_before_admin_db(): void
    {
        $kernel = app(Kernel::class);
        $ref = new \ReflectionClass($kernel);
        $prop = $ref->getProperty('middlewarePriority');
        $prop->setAccessible(true);
        $priority = $prop->getValue($kernel);

        $authPos = array_search(\Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, $priority, true);
        $superadminPos = array_search(\App\Http\Middleware\EnsureSuperAdmin::class, $priority, true);
        $adminDbPos = array_search(\App\Http\Middleware\UseAdminDatabaseConnection::class, $priority, true);
        $bindingsPos = array_search(\Illuminate\Routing\Middleware\SubstituteBindings::class, $priority, true);

        $this->assertNotFalse($authPos);
        $this->assertNotFalse($superadminPos);
        $this->assertNotFalse($adminDbPos);
        $this->assertNotFalse($bindingsPos);

        $this->assertTrue($authPos < $superadminPos, 'EnsureSuperAdmin must run after authentication.');
        $this->assertTrue($superadminPos < $adminDbPos, 'admin-db must run after EnsureSuperAdmin.');
        $this->assertTrue($adminDbPos < $bindingsPos, 'admin-db must still run before route-model-binding.');
    }

    public function test_a_non_superadmin_gets_the_same_403_whether_or_not_the_tenant_id_exists(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');

        $existing = $this->actingAs($admin)->get("/superadmin/tenants/{$tenant->id}");
        $nonExisting = $this->actingAs($admin)->get('/superadmin/tenants/'.\Illuminate\Support\Str::uuid());

        $existing->assertForbidden();
        $nonExisting->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login_without_leaking_whether_the_tenant_id_exists(): void
    {
        $tenant = $this->createTestTenant();

        $existing = $this->get("/superadmin/tenants/{$tenant->id}");
        $nonExisting = $this->get('/superadmin/tenants/'.\Illuminate\Support\Str::uuid());

        $existing->assertRedirect(route('login'));
        $nonExisting->assertRedirect(route('login'));
    }
}
