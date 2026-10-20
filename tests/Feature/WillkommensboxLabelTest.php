<?php

namespace Tests\Feature;

use App\Models\WillkommensboxOrder;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Versandlabel-PDF-Download für eine Willkommensbox-Bestellung (siehe
 * WillkommensboxLabelController) -- welche Papiergrößen (A4/A5/A6)
 * angeboten werden, legt jede Bootsschule im Branding-Tab fest (siehe
 * TenantBranding::enabledLabelSizes). A4 ist standardmäßig aktiv.
 */
class WillkommensboxLabelTest extends TestCase
{
    use InteractsWithTenants;

    private function createOrder($tenant, $user): WillkommensboxOrder
    {
        return $this->onAdmin(fn () => WillkommensboxOrder::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'street' => 'Lieferstraße 1',
            'postal_code' => '12345',
            'city' => 'Lieferstadt',
            'country' => 'Deutschland',
        ]));
    }

    public function test_new_tenants_default_to_only_a4_enabled(): void
    {
        $tenant = $this->createTestTenant();

        $branding = $tenant->branding->fresh();
        $this->assertTrue($branding->label_size_a4);
        $this->assertFalse($branding->label_size_a5);
        $this->assertFalse($branding->label_size_a6);
        $this->assertSame(['a4'], $branding->enabledLabelSizes());
    }

    public function test_a_school_admin_can_configure_the_enabled_label_sizes(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/branding', [
            'primary_color' => '#005FD7',
            'secondary_color' => '#00A8A8',
            'exam_readiness_threshold_percent' => 50,
            'label_size_a5' => '1',
            'label_size_a6' => '1',
        ]);

        $response->assertRedirect();
        $branding = $tenant->branding->fresh();
        $this->assertFalse($branding->label_size_a4);
        $this->assertTrue($branding->label_size_a5);
        $this->assertTrue($branding->label_size_a6);
    }

    public function test_participant_page_shows_a_download_link_for_each_enabled_size(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $order = $this->createOrder($tenant, $learner);
        $this->onAdmin(fn () => $tenant->branding->update(['label_size_a4' => true, 'label_size_a5' => true, 'label_size_a6' => false]));

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        $response->assertSee(route('admin.willkommensbox-orders.label', [$order, 'a4']), false);
        $response->assertSee(route('admin.willkommensbox-orders.label', [$order, 'a5']), false);
        $response->assertDontSee(route('admin.willkommensbox-orders.label', [$order, 'a6']), false);
    }

    public function test_participant_page_shows_no_label_links_without_an_order(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        $response->assertDontSee('Versandlabel:');
    }

    public function test_a_school_admin_can_download_a_label_pdf_in_an_enabled_size(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $order = $this->createOrder($tenant, $learner);

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.willkommensbox-orders.label', [$order, 'a4']));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_downloading_a_disabled_label_size_is_forbidden(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $order = $this->createOrder($tenant, $learner);

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.willkommensbox-orders.label', [$order, 'a6']));

        $response->assertForbidden();
    }

    public function test_downloading_an_unknown_size_is_not_found(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $order = $this->createOrder($tenant, $learner);

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.willkommensbox-orders.label', [$order, 'a3']));

        $response->assertNotFound();
    }

    public function test_a_school_admin_cannot_download_the_label_of_another_tenants_order(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminB = $this->createTenantUser($tenantB, 'owner');
        $learnerA = $this->createTenantUser($tenantA, 'learner');
        $order = $this->createOrder($tenantA, $learnerA);

        $response = $this->actingAsInTenant($adminB, $tenantB)->get(route('admin.willkommensbox-orders.label', [$order, 'a4']));

        $response->assertNotFound();
    }
}
