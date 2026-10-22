<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * DSGVO-Review D3: Auskunfts-/Mitnahmerecht (Art. 15/20) -- bislang gab es
 * keinen Export-Endpoint.
 */
class PersonalDataExportTest extends TestCase
{
    use InteractsWithTenants;

    public function test_a_learner_can_export_their_data_at_the_current_tenant(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get('/profile/export-data');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertHeader('Content-Disposition');

        $data = json_decode($response->getContent(), true);

        $this->assertSame($tenant->id, $data['tenant']['id']);
        $this->assertSame($learner->email, $data['profile']['email']);
        $this->assertSame('learner', $data['membership']['role']);
        $this->assertCount(1, $data['entitlements']);
        $this->assertSame($course->name, $data['entitlements'][0]['course']);
    }

    public function test_the_export_only_contains_data_from_the_current_tenant(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $user = $this->createTenantUser($tenantA, 'learner');
        $this->onAdmin(fn () => \App\Models\TenantUser::create([
            'tenant_id' => $tenantB->id,
            'user_id' => $user->id,
            'role' => 'learner',
            'status' => 'active',
        ]));

        $courseA = $this->existingCourse('SRC');
        $courseB = $this->existingCourse('UBI');
        $this->grantEntitlement($tenantA, $user, $courseA);
        $this->grantEntitlement($tenantB, $user, $courseB);

        $response = $this->actingAsInTenant($user, $tenantA)->get('/profile/export-data');

        $data = json_decode($response->getContent(), true);

        $this->assertCount(1, $data['entitlements']);
        $this->assertSame($courseA->name, $data['entitlements'][0]['course']);

        $this->createdUserIds[] = $user->id;
    }

    public function test_the_export_requires_authentication(): void
    {
        $tenant = $this->createTestTenant();

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/profile/export-data');

        $response->assertRedirect('/login');
    }
}
