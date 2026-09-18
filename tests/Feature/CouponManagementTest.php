<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\Product;
use App\Models\TenantCourseDisabled;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Zwei Wege, wie eine Bootsschule an eigene Coupon-Codes kommt: Import
 * extern gekaufter Codes (Admin\CouponController::import) oder direkte
 * Zuteilung durch den Superadmin (bereits in SuperadminAreaTest geprüft).
 * Aus dem eigenen Bestand kann die Bootsschule wiederum einem bereits
 * registrierten Nutzer direkt einen Code für einen Kurs zuweisen
 * (Admin\CouponController::assign) statt ihn selbst einlösen zu lassen.
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

    public function test_admin_can_import_coupon_codes_for_a_course_they_offer(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');
        $codes = ['E2E-IMP-0001', 'E2E-IMP-0002', 'E2E-IMP-0003'];
        $this->createdCodes = array_merge($this->createdCodes, $codes);

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/coupons/import', [
            'target' => 'course:'.$course->id,
            'codes' => implode("\n", $codes),
        ]);

        $response->assertSessionHasNoErrors();
        foreach ($codes as $code) {
            $this->assertDatabaseHas('coupon', [
                'code' => $code,
                'course_id' => $course->id,
                'tenant_id' => $tenant->id,
                'redeemed_at' => null,
            ]);
        }
    }

    public function test_admin_cannot_import_codes_for_a_course_their_tenant_does_not_offer(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');
        $this->onAdmin(fn () => TenantCourseDisabled::firstOrCreate(['tenant_id' => $tenant->id, 'course_id' => $course->id]));
        $code = 'E2E-IMP-DISABLED';
        $this->createdCodes[] = $code;

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/coupons/import', [
            'target' => 'course:'.$course->id,
            'codes' => $code,
        ]);

        $response->assertSessionHasErrors('target');
        $this->assertDatabaseMissing('coupon', ['code' => $code]);
    }

    public function test_admin_can_import_coupon_codes_for_a_product(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $product = $this->onAdmin(fn () => Product::where('active', true)->firstOrFail());
        $code = 'E2E-IMP-PRODUCT';
        $this->createdCodes[] = $code;

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/coupons/import', [
            'target' => 'product:'.$product->id,
            'codes' => $code,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('coupon', [
            'code' => $code,
            'product_id' => $product->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_importing_an_already_existing_code_is_skipped_and_reported(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminB = $this->createTenantUser($tenantB, 'owner');
        $course = $this->existingCourse('SRC');
        $code = 'E2E-IMP-DUPLICATE';
        $this->createdCodes[] = $code;

        // Von tenantA importiert -- für tenantB per RLS unsichtbar, belegt aber
        // trotzdem den global eindeutigen Code.
        $this->onAdmin(fn () => Coupon::create([
            'code' => $code,
            'course_id' => $course->id,
            'tenant_id' => $tenantA->id,
        ]));

        $response = $this->actingAsInTenant($adminB, $tenantB)->post('/admin/coupons/import', [
            'target' => 'course:'.$course->id,
            'codes' => $code,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status', fn ($status) => str_contains($status, 'bereits vorhanden'));
        $this->onAdmin(fn () => $this->assertSame(1, Coupon::where('code', $code)->count()));
        $this->onAdmin(fn () => $this->assertSame($tenantA->id, Coupon::where('code', $code)->first()->tenant_id));
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
}
