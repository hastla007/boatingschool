<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductPurchase;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Produkte (z. B. Fahrstunden) sind Einzelleistungen ohne Kursinhalt, die
 * wie Kurse per Coupon an Nutzer vergeben werden -- aber ihren eigenen
 * Kaufnachweis (product_purchase statt entitlement) führen, weil ein
 * Produktkauf ein einmaliges Ereignis ist statt eines laufenden Zugangs.
 */
class ProductTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdProductIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdProductIds as $id) {
                ProductPurchase::where('product_id', $id)->delete();
                Coupon::where('product_id', $id)->delete();
                Product::where('id', $id)->delete();
            }
        });

        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    private function createTestProduct(string $name = 'E2E Testprodukt'): Product
    {
        return $this->onAdmin(function () use ($name) {
            $product = Product::create(['code' => 'E2E-'.uniqid(), 'name' => $name, 'active' => true]);
            $this->createdProductIds[] = $product->id;

            return $product;
        });
    }

    public function test_the_two_seeded_driving_lesson_products_exist(): void
    {
        $this->assertDatabaseHas('product', ['code' => 'FAHRSTUNDE-1-EH', 'name' => 'Fahrstunde 1 EH']);
        $this->assertDatabaseHas('product', ['code' => 'FAHRSTUNDE-2-EH', 'name' => 'Fahrstunde 2 EH']);
    }

    public function test_superadmin_can_create_a_product(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->post('/superadmin/products', [
            'code' => 'E2E-NEW-PRODUCT',
            'name' => 'E2E Neues Produkt',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('product', ['code' => 'E2E-NEW-PRODUCT', 'name' => 'E2E Neues Produkt', 'active' => true]);

        $product = Product::where('code', 'E2E-NEW-PRODUCT')->first();
        $this->createdProductIds[] = $product->id;
    }

    public function test_superadmin_can_generate_coupons_for_a_product(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $product = $this->createTestProduct();

        $response = $this->actingAs($superadmin)->post('/superadmin/coupons', [
            'target' => 'product:'.$product->id,
            'tenant_id' => $tenant->id,
            'quantity' => 3,
            'batch_label' => 'E2E Produkt-Batch',
        ]);

        $response->assertRedirect();
        $this->assertSame(3, Coupon::where('product_id', $product->id)->where('tenant_id', $tenant->id)->count());
        $this->assertDatabaseHas('coupon', ['product_id' => $product->id, 'course_id' => null]);
    }

    public function test_redeeming_a_product_coupon_creates_a_purchase_record_visible_in_profile_and_admin(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $admin = $this->createTenantUser($tenant, 'owner');
        $product = $this->createTestProduct('E2E Fahrstunde');
        $coupon = $this->onAdmin(fn () => Coupon::create([
            'code' => Coupon::generateUniqueCode(),
            'product_id' => $product->id,
            'tenant_id' => $tenant->id,
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $coupon->code]);

        $response->assertRedirect(route('profile.edit', absolute: false));
        $this->assertDatabaseHas('product_purchase', [
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'product_id' => $product->id,
            'source_type' => 'coupon',
            'source_reference' => $coupon->code,
        ]);

        $coupon->refresh();
        $this->assertTrue($coupon->isRedeemed());
        $this->assertSame($learner->id, $coupon->redeemed_by_user_id);

        // sichtbar im eigenen Profil
        $profile = $this->actingAsInTenant($learner, $tenant)->get('/profile');
        $profile->assertOk();
        $profile->assertSee('E2E Fahrstunde');

        // sichtbar für die Bootsschule (Teilnehmer-Übersicht)
        $participants = $this->actingAsInTenant($admin, $tenant)->get('/admin/participants');
        $participants->assertOk();
        $participants->assertSee('E2E Fahrstunde');
    }

    public function test_a_product_purchase_is_visible_in_superadmin_user_detail(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $product = $this->createTestProduct('E2E Sichtbares Produkt');
        $this->onAdmin(fn () => ProductPurchase::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'product_id' => $product->id,
            'source_type' => 'coupon',
            'source_reference' => 'TEST-CODE',
        ]));

        $response = $this->actingAs($superadmin)->get("/superadmin/users/{$learner->id}");

        $response->assertOk();
        $response->assertSee('E2E Sichtbares Produkt');
    }

    public function test_redeemed_coupons_are_visible_to_the_tenant_admin_with_who_and_when(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $admin = $this->createTenantUser($tenant, 'owner');
        $product = $this->createTestProduct('E2E Coupon Sichtbarkeit');
        $coupon = $this->onAdmin(fn () => Coupon::create([
            'code' => Coupon::generateUniqueCode(),
            'product_id' => $product->id,
            'tenant_id' => $tenant->id,
        ]));

        $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $coupon->code]);

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin/coupons');

        $response->assertOk();
        $response->assertSee($coupon->code);
        $response->assertSee($learner->name);
    }

    public function test_redeeming_a_coupon_for_a_deactivated_product_is_rejected(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $product = $this->createTestProduct();
        $coupon = $this->onAdmin(fn () => Coupon::create([
            'code' => Coupon::generateUniqueCode(),
            'product_id' => $product->id,
            'tenant_id' => null,
        ]));
        $this->onAdmin(fn () => $product->update(['active' => false]));

        $response = $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $coupon->code]);

        $response->assertSessionHasErrors('code');
        $this->assertDatabaseMissing('product_purchase', ['tenant_id' => $tenant->id, 'product_id' => $product->id]);
    }

    public function test_a_coupon_cannot_target_both_a_course_and_a_product(): void
    {
        $course = $this->existingCourse('SRC');
        $product = $this->createTestProduct();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->onAdmin(fn () => Coupon::create([
            'code' => Coupon::generateUniqueCode(),
            'course_id' => $course->id,
            'product_id' => $product->id,
        ]));
    }
}
