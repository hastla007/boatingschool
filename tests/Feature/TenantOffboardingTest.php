<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Vertragsende einer Bootsschule: Export aller Daten und endgültige
 * Löschung durch den Superadmin (TenantOffboardingService).
 */
class TenantOffboardingTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    public function test_the_superadmin_exports_all_data_of_a_school(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant('Export Schule');
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $this->existingCourse('SRC'));

        $response = $this->actingAs($superadmin)->get("/superadmin/tenants/{$tenant->id}/export")->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $data = $response->json();
        $this->assertSame('Export Schule', $data['tenant']['name']);
        $this->assertSame($learner->email, $data['members'][0]['data']['profile']['email']);
        $this->assertNotEmpty($data['members'][0]['data']['entitlements'] ?? $data['members'][0]['data']);
    }

    public function test_only_a_closed_school_can_be_deleted_and_orphaned_accounts_go_with_it(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant('Lösch Schule');
        $other = $this->createTestTenant('Bleibt');
        $onlyHere = $this->createTenantUser($tenant, 'learner');
        $alsoElsewhere = $this->createTenantUser($tenant, 'instructor');
        $this->onAdmin(fn () => TenantUser::create(['tenant_id' => $other->id, 'user_id' => $alsoElsewhere->id, 'role' => 'learner', 'status' => 'active']));
        $this->grantEntitlement($tenant, $onlyHere, $this->existingCourse('SRC'));

        // Noch aktiv: Löschen verweigert.
        $this->actingAs($superadmin)->delete("/superadmin/tenants/{$tenant->id}", ['confirm_slug' => $tenant->slug])->assertSessionHasErrors('confirm_slug');
        $this->onAdmin(fn () => $tenant->update(['status' => 'closed']));
        // Falscher Kurzname: verweigert.
        $this->actingAs($superadmin)->delete("/superadmin/tenants/{$tenant->id}", ['confirm_slug' => 'falsch'])->assertSessionHasErrors('confirm_slug');
        $this->onAdmin(fn () => $this->assertNotNull(Tenant::find($tenant->id)));

        $this->actingAs($superadmin)->delete("/superadmin/tenants/{$tenant->id}", ['confirm_slug' => $tenant->slug])
            ->assertRedirect(route('superadmin.tenants.index'));

        $this->onAdmin(function () use ($tenant, $other, $onlyHere, $alsoElsewhere) {
            $this->assertNull(Tenant::find($tenant->id));
            $this->assertSame(0, Entitlement::where('tenant_id', $tenant->id)->count());
            $this->assertSame(0, AuditLog::where('tenant_id', $tenant->id)->count());
            $this->assertNull(User::find($onlyHere->id), 'Konto ohne weitere Schule gelöscht');
            $this->assertNotNull(User::find($alsoElsewhere->id), 'Konto mit weiterer Schule bleibt');
            $this->assertTrue(TenantUser::where('tenant_id', $other->id)->where('user_id', $alsoElsewhere->id)->exists());
            $log = AuditLog::where('action', 'superadmin.tenant.delete')->latest('created_at')->first();
            $this->assertSame($tenant->slug, $log->after_data['slug']);
            $this->assertSame(1, $log->after_data['deleted_accounts']);
        });
    }
}
