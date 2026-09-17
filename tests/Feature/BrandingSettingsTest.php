<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class BrandingSettingsTest extends TestCase
{
    use InteractsWithTenants;

    public function test_new_tenants_default_to_a_fifty_percent_exam_readiness_threshold(): void
    {
        $tenant = $this->createTestTenant();

        $this->assertSame(50, $tenant->branding->fresh()->exam_readiness_threshold_percent);
    }

    public function test_a_school_admin_can_configure_the_exam_readiness_threshold(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/branding', [
            'primary_color' => '#005FD7',
            'secondary_color' => '#00A8A8',
            'exam_readiness_threshold_percent' => 75,
        ]);

        $response->assertRedirect();
        $this->assertSame(75, $tenant->branding->fresh()->exam_readiness_threshold_percent);
    }

    public function test_exam_readiness_threshold_must_be_a_percentage(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/branding', [
            'primary_color' => '#005FD7',
            'secondary_color' => '#00A8A8',
            'exam_readiness_threshold_percent' => 150,
        ]);

        $response->assertSessionHasErrors('exam_readiness_threshold_percent');
    }
}
