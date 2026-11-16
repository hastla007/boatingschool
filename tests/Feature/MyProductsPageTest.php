<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductPurchase;
use App\Models\TenantUser;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * „Meine Produkte“: nach dem Einlösen eines Produkt-Codes sieht der Lerner,
 * was er bekommen hat und wie es weitergeht (vorher landete er im Profil,
 * das dazu nichts sagte).
 */
class MyProductsPageTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCodes = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdCodes as $code) {
                ProductPurchase::where('source_reference', $code)->delete();
                Coupon::where('code', $code)->delete();
            }
        });

        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    private function productCoupon(string $productCode, ?string $tenantId): Coupon
    {
        return $this->onAdmin(function () use ($productCode, $tenantId) {
            $coupon = Coupon::create([
                'code' => Coupon::generateUniqueCode(),
                'product_id' => Product::where('code', $productCode)->value('id'),
                'tenant_id' => $tenantId,
            ]);
            $this->createdCodes[] = $coupon->code;

            return $coupon;
        });
    }

    public function test_redeeming_a_product_code_leads_to_my_products_with_the_next_step(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $coupon = $this->productCoupon('FAHRSTUNDE-1-EH', $tenant->id);

        $response = $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $coupon->code]);

        $response->assertRedirect(route('products.index', absolute: false));
        $response->assertSessionHas('status', fn ($status) => str_contains($status, 'Fahrstunde 1 EH'));

        $page = $this->actingAsInTenant($learner, $tenant)->get('/produkte');
        $page->assertOk();
        $page->assertSee('Meine Produkte');
        $page->assertSee('Fahrstunde 1 EH');
        $page->assertSee('Eingelöst mit Code '.$coupon->code);
        $page->assertSee('Deine Bootsschule meldet sich bei dir');
    }

    public function test_a_practice_unit_bundled_with_a_course_code_links_to_praxis_und_pruefung(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $coupon = $this->onAdmin(function () use ($course, $tenant) {
            $coupon = Coupon::create(['code' => Coupon::generateUniqueCode(), 'course_id' => $course->id, 'tenant_id' => $tenant->id]);
            $coupon->optionProducts()->attach(Product::where('code', 'PRAXISEINHEIT-KLEIN')->value('id'));
            $this->createdCodes[] = $coupon->code;

            return $coupon;
        });

        $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $coupon->code])
            ->assertRedirect(route('courses.show', $course, absolute: false));

        $page = $this->actingAsInTenant($learner, $tenant)->get('/produkte');
        $page->assertOk();
        $page->assertSee('Kleine Praxiseinheit');
        $page->assertSee('Zu Praxis &amp; Prüfung', false);
        $page->assertSee(route('praxis-pruefung.index', $course));
    }

    public function test_the_navigation_shows_my_products_only_when_the_learner_has_any(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $this->actingAsInTenant($learner, $tenant)->get('/dashboard')
            ->assertOk()->assertDontSee(route('products.index'));

        $this->onAdmin(fn () => ProductPurchase::create([
            'tenant_id' => $tenant->id, 'user_id' => $learner->id,
            'product_id' => Product::where('code', 'FAHRSTUNDE-2-EH')->value('id'), 'source_type' => 'manual',
        ]));

        $dashboard = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');
        $dashboard->assertOk();
        $dashboard->assertSee(route('products.index'));
        $dashboard->assertSee('Meine Produkte');

        $page = $this->actingAsInTenant($learner, $tenant)->get('/produkte');
        $page->assertSee('Fahrstunde 2 EH');
        $page->assertSee('Von deiner Bootsschule gebucht');
    }

    public function test_purchases_from_another_school_are_not_listed(): void
    {
        $tenantA = $this->createTestTenant('Schule A');
        $tenantB = $this->createTestTenant('Schule B');
        $learner = $this->createTenantUser($tenantA, 'learner');
        $this->onAdmin(function () use ($tenantB, $learner) {
            TenantUser::create(['tenant_id' => $tenantB->id, 'user_id' => $learner->id, 'role' => 'learner', 'status' => 'active']);
            ProductPurchase::create([
                'tenant_id' => $tenantB->id, 'user_id' => $learner->id,
                'product_id' => Product::where('code', 'FAHRSTUNDE-2-EH')->value('id'), 'source_type' => 'manual',
            ]);
        });

        $page = $this->actingAsInTenant($learner, $tenantA)->get('/produkte');

        $page->assertOk();
        $page->assertDontSee('Fahrstunde 2 EH');
        $page->assertSee('Du hast noch keine Produkte.');
    }

    public function test_the_page_requires_login(): void
    {
        $tenant = $this->createTestTenant();

        $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/produkte')->assertRedirect();
    }
}
