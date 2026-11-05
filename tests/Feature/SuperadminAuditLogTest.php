<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class SuperadminAuditLogTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->onAdmin(fn () => AuditLog::where('action', 'like', 'test-%')->delete());
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    public function test_superadmin_filters_the_log_by_school_person_action_and_object(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant('Protokoll Schule');
        $admin = $this->createTenantUser($tenant, 'admin');
        $marker = 'test-'.uniqid();
        $this->onAdmin(function () use ($tenant, $admin, $marker, $superadmin) {
            AuditLog::record($tenant->id, $admin->id, "{$marker}.branding", 'tenant_branding', $tenant->id, ['farbe' => 'alt'], ['farbe' => 'neu']);
            AuditLog::record(null, $superadmin->id, "{$marker}.platform", 'platform_setting', null, null, ['x' => 1]);
        });

        $page = $this->actingAs($superadmin)->get('/superadmin/audit?action='.$marker)->assertOk();
        $page->assertSee("{$marker}.branding")->assertSee("{$marker}.platform")->assertSee('Protokoll Schule')->assertSee('&quot;farbe&quot;: &quot;neu&quot;', false);

        $this->actingAs($superadmin)->get("/superadmin/audit?action={$marker}&tenant={$tenant->id}")->assertSee("{$marker}.branding")->assertDontSee("{$marker}.platform");
        $this->actingAs($superadmin)->get("/superadmin/audit?action={$marker}&tenant=platform")->assertDontSee("{$marker}.branding")->assertSee("{$marker}.platform");
        $this->actingAs($superadmin)->get("/superadmin/audit?action={$marker}&actor=".urlencode($admin->email))->assertSee("{$marker}.branding")->assertDontSee("{$marker}.platform");
        $this->actingAs($superadmin)->get("/superadmin/audit?entity={$tenant->id}&action={$marker}")->assertSee("{$marker}.branding");
        $this->actingAs($superadmin)->get("/superadmin/audit?action={$marker}&from=2000-01-01&to=2000-01-02")->assertSee('Keine Einträge gefunden');
        // Manipulierte Parameter: kein Fehler.
        $this->actingAs($superadmin)->get('/superadmin/audit?tenant[]=x&from=kaputt&entity=keine-uuid')->assertOk();

        $this->actingAsInTenant($admin, $tenant)->get('/superadmin/audit')->assertForbidden();
    }
}
