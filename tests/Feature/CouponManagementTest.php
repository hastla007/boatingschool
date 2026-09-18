<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\Product;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Zwei Wege, wie eine Bootsschule an eigene Coupon-Codes kommt: der
 * Superadmin teilt direkt welche zu (bereits in SuperadminAreaTest
 * geprüft), oder die Bootsschule übernimmt selbst Codes in ihren Bestand
 * (Admin\CouponController::import) -- die Codes existieren dafür schon
 * (mit fest zugeordnetem Kurs/Produkt, aber noch ohne Bootsschule), Import
 * setzt nur noch tenant_id; welchen Kurs/welches Produkt ein Code
 * freischaltet, muss die Bootsschule deshalb nicht angeben. Aus dem
 * eigenen Bestand kann sie wiederum einem bereits registrierten Nutzer
 * direkt einen Code für einen Kurs zuweisen (Admin\CouponController::assign)
 * statt ihn selbst einlösen zu lassen.
 */
class CouponManagementTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCodes = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdCodes as $code) {
                Coupon::where('code', $code)->delete();
            }
        });

        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_admin_can_import_pre_generated_codes_and_the_course_is_recognized_automatically(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');
        $codes = ['E2E-IMP-0001', 'E2E-IMP-0002', 'E2E-IMP-0003'];
        $this->createdCodes = array_merge($this->createdCodes, $codes);
        // Codes existieren schon (z. B. vom Superadmin ohne Bootsschule
        // erzeugt, dann über einen externen Webshop verkauft) -- Kurs steht
        // also bereits fest, bevor die Bootsschule sie importiert.
        foreach ($codes as $code) {
            $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => null]));
        }

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/coupons/import', [
            'codes' => implode("\n", $codes),
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status', fn ($status) => str_contains($status, '3 Codes übernommen'));
        foreach ($codes as $code) {
            $this->assertDatabaseHas('coupon', [
                'code' => $code,
                'course_id' => $course->id,
                'tenant_id' => $tenant->id,
                'redeemed_at' => null,
            ]);
        }
    }

    public function test_admin_can_import_a_pre_generated_product_code(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $product = $this->onAdmin(fn () => Product::where('active', true)->firstOrFail());
        $code = 'E2E-IMP-PRODUCT';
        $this->createdCodes[] = $code;
        $this->onAdmin(fn () => Coupon::create(['code' => $code, 'product_id' => $product->id, 'tenant_id' => null]));

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/coupons/import', [
            'codes' => $code,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('coupon', [
            'code' => $code,
            'product_id' => $product->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_importing_an_unknown_code_is_reported_as_unavailable(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/coupons/import', [
            'codes' => 'DOES-NOT-EXIST-0001',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status', fn ($status) => str_contains($status, '0 Codes übernommen') && str_contains($status, 'unbekannt oder nicht verfügbar'));
    }

    public function test_importing_a_code_already_assigned_to_another_tenant_is_reported_as_unavailable(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminB = $this->createTenantUser($tenantB, 'owner');
        $course = $this->existingCourse('SRC');
        $code = 'E2E-IMP-OTHER-TENANT';
        $this->createdCodes[] = $code;
        $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenantA->id]));

        $response = $this->actingAsInTenant($adminB, $tenantB)->post('/admin/coupons/import', [
            'codes' => $code,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status', fn ($status) => str_contains($status, 'unbekannt oder nicht verfügbar'));
        $this->onAdmin(fn () => $this->assertSame($tenantA->id, Coupon::where('code', $code)->first()->tenant_id));
    }

    public function test_importing_a_code_already_owned_is_a_no_op_reported_as_already_owned(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');
        $code = 'E2E-IMP-ALREADY-OWNED';
        $this->createdCodes[] = $code;
        $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/coupons/import', [
            'codes' => $code,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status', fn ($status) => str_contains($status, '0 Codes übernommen') && str_contains($status, 'bereits im eigenen Bestand'));
    }

    public function test_importing_an_already_redeemed_code_is_reported_and_not_reclaimed(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $code = 'E2E-IMP-REDEEMED';
        $this->createdCodes[] = $code;
        $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => null]));
        $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $code]);

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/coupons/import', [
            'codes' => $code,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status', fn ($status) => str_contains($status, 'bereits eingelöst'));
    }

    public function test_admin_coupon_tab_shows_free_and_used_counts_per_course(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $free = ['E2E-SUM-FREE-1', 'E2E-SUM-FREE-2'];
        $used = 'E2E-SUM-USED-1';
        $this->createdCodes = array_merge($this->createdCodes, $free, [$used]);

        foreach ($free as $code) {
            $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));
        }
        $usedCoupon = $this->onAdmin(fn () => Coupon::create(['code' => $used, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));
        $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $used]);

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin');

        $response->assertOk();
        $pattern = '/'.preg_quote($course->name, '/').'.*?text-emerald-600 font-medium">2<.*?text-slate-500">1</s';
        $this->assertMatchesRegularExpression($pattern, $response->getContent());
    }

    public function test_admin_can_assign_a_free_code_to_a_participant_for_a_course(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $code = 'E2E-ASSIGN-COURSE';
        $this->createdCodes[] = $code;
        $coupon = $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        $response = $this->actingAsInTenant($admin, $tenant)->post("/admin/coupons/{$coupon->id}/assign", [
            'user_id' => $learner->id,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('entitlement', [
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'course_id' => $course->id,
            'source_type' => 'coupon',
            'source_reference' => $code,
        ]);
        $coupon->refresh();
        $this->assertTrue($coupon->isRedeemed());
        $this->assertSame($learner->id, $coupon->redeemed_by_user_id);

        $courseShow = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");
        $courseShow->assertOk();
    }

    public function test_admin_cannot_assign_a_code_to_a_user_outside_their_tenant(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminA = $this->createTenantUser($tenantA, 'owner');
        $learnerB = $this->createTenantUser($tenantB, 'learner');
        $course = $this->existingCourse('SRC');
        $code = 'E2E-ASSIGN-CROSS-TENANT';
        $this->createdCodes[] = $code;
        $coupon = $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenantA->id]));

        $response = $this->actingAsInTenant($adminA, $tenantA)->post("/admin/coupons/{$coupon->id}/assign", [
            'user_id' => $learnerB->id,
        ]);

        $response->assertSessionHasErrors('assign');
        $coupon->refresh();
        $this->assertFalse($coupon->isRedeemed());
        $this->assertDatabaseMissing('entitlement', ['tenant_id' => $tenantA->id, 'user_id' => $learnerB->id, 'course_id' => $course->id]);
    }

    public function test_admin_cannot_assign_an_already_redeemed_code(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner1 = $this->createTenantUser($tenant, 'learner');
        $learner2 = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $code = 'E2E-ASSIGN-REDEEMED';
        $this->createdCodes[] = $code;
        $coupon = $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));
        $this->actingAsInTenant($learner1, $tenant)->post('/coupons/redeem', ['code' => $code]);

        $response = $this->actingAsInTenant($admin, $tenant)->post("/admin/coupons/{$coupon->id}/assign", [
            'user_id' => $learner2->id,
        ]);

        $response->assertNotFound();
        $this->assertSame(1, Entitlement::where('tenant_id', $tenant->id)->where('course_id', $course->id)->count());
    }

    public function test_coupon_redeem_menu_link_is_visible_for_a_learner(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Coupon-Code einlösen');
        $response->assertSee(route('coupons.redeem', absolute: false), false);
    }

    public function test_coupon_list_can_be_searched_by_code(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');
        $codes = ['E2E-SEARCH-FINDME', 'E2E-SEARCH-OTHER'];
        $this->createdCodes = array_merge($this->createdCodes, $codes);
        foreach ($codes as $code) {
            $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));
        }

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=coupons&coupon_search=FINDME');

        $response->assertOk();
        $response->assertSee('E2E-SEARCH-FINDME');
        $response->assertDontSee('E2E-SEARCH-OTHER');
    }

    public function test_coupon_list_can_be_searched_by_redeemed_user_name(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Gesuchte Lernperson']);
        $otherLearner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Andere Person']);
        $course = $this->existingCourse('SRC');
        $codeA = 'E2E-SEARCH-USER-A';
        $codeB = 'E2E-SEARCH-USER-B';
        $this->createdCodes = array_merge($this->createdCodes, [$codeA, $codeB]);
        $this->onAdmin(fn () => Coupon::create(['code' => $codeA, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));
        $this->onAdmin(fn () => Coupon::create(['code' => $codeB, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));
        $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $codeA]);
        $this->actingAsInTenant($otherLearner, $tenant)->post('/coupons/redeem', ['code' => $codeB]);

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=coupons&coupon_search=Gesuchte');

        $response->assertOk();
        $response->assertSee($codeA);
        $response->assertDontSee($codeB);
    }

    public function test_coupon_list_can_be_filtered_by_status(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $openCode = 'E2E-FILTER-OPEN';
        $redeemedCode = 'E2E-FILTER-REDEEMED';
        $this->createdCodes = array_merge($this->createdCodes, [$openCode, $redeemedCode]);
        $this->onAdmin(fn () => Coupon::create(['code' => $openCode, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));
        $this->onAdmin(fn () => Coupon::create(['code' => $redeemedCode, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));
        $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $redeemedCode]);

        $openOnly = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=coupons&coupon_status=open');
        $openOnly->assertOk();
        $openOnly->assertSee($openCode);
        $openOnly->assertDontSee($redeemedCode);

        $redeemedOnly = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=coupons&coupon_status=redeemed');
        $redeemedOnly->assertOk();
        $redeemedOnly->assertSee($redeemedCode);
        $redeemedOnly->assertDontSee($openCode);
    }

    public function test_coupon_list_can_be_filtered_by_type(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');
        $product = $this->onAdmin(fn () => Product::where('active', true)->firstOrFail());
        $courseCode = 'E2E-FILTER-COURSE';
        $productCode = 'E2E-FILTER-PRODUCT';
        $this->createdCodes = array_merge($this->createdCodes, [$courseCode, $productCode]);
        $this->onAdmin(fn () => Coupon::create(['code' => $courseCode, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));
        $this->onAdmin(fn () => Coupon::create(['code' => $productCode, 'product_id' => $product->id, 'tenant_id' => $tenant->id]));

        $coursesOnly = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=coupons&coupon_type=course');
        $coursesOnly->assertOk();
        $coursesOnly->assertSee($courseCode);
        $coursesOnly->assertDontSee($productCode);

        $productsOnly = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=coupons&coupon_type=product');
        $productsOnly->assertOk();
        $productsOnly->assertSee($productCode);
        $productsOnly->assertDontSee($courseCode);
    }
}
