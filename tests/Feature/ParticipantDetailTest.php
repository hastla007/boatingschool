<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPurchase;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Detailseite je Teilnehmer (ParticipantController::show): Adresse,
 * gebuchte Kurse/Produkte und Praxis-Anmeldungen an einem Ort, erreichbar
 * über den Namen in der Teilnehmer-Tabelle (admin/tabs/participants.blade.php).
 */
class ParticipantDetailTest extends TestCase
{
    use InteractsWithTenants;

    public function test_admin_sees_a_participants_address_and_bookings_on_the_detail_page(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner', [
            'street' => 'Hafenstraße 5',
            'postal_code' => '20457',
            'city' => 'Hamburg',
            'country' => 'Deutschland',
            'birthdate' => '1995-06-15',
        ]);
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);
        $product = $this->onAdmin(fn () => Product::where('active', true)->firstOrFail());
        $this->onAdmin(fn () => ProductPurchase::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'product_id' => $product->id,
            'source_type' => 'manual',
            'source_reference' => 'admin:'.$admin->id,
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}");

        $response->assertOk();
        $response->assertSee('Hafenstraße 5');
        $response->assertSee('20457 Hamburg');
        $response->assertSee('Deutschland');
        $response->assertSee('15.06.1995');
        $response->assertSee($course->name);
        $response->assertSee($product->name);
    }

    public function test_detail_page_is_linked_from_the_participants_table(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=participants');

        $response->assertOk();
        $response->assertSee(route('admin.participants.show', $learner->id), false);
    }

    public function test_admin_cannot_view_a_participant_from_another_tenant(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminA = $this->createTenantUser($tenantA, 'owner');
        $learnerB = $this->createTenantUser($tenantB, 'learner');

        $response = $this->actingAsInTenant($adminA, $tenantA)->get("/admin/participants/{$learnerB->id}");

        $response->assertNotFound();
    }
}
