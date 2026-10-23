<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Entitlement;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Verlängerungscodes sind Kurs-Coupons mit is_renewal=true (siehe
 * 2024_02_01_000452_add_is_renewal_to_coupon_table). Der Superadmin erzeugt
 * sie wie jeden anderen Coupon (Superadmin\CouponController::store, Ziel
 * "renewal:<course_id>"); eingelöst verlängern sie aber ein bestehendes
 * Entitlement statt ein neues anzulegen (CouponRedemptionService::
 * redeemForRenewal), auf zwei Wegen:
 * - Ablauf 1: der Teilnehmer löst den Code selbst über /coupons/redeem ein.
 * - Ablauf 2: die Bootsschule weist ihn direkt über die Coupon-Codes-Tab
 *   ("Zuweisen", Admin\CouponController::assign) einem Teilnehmer zu.
 */
class RenewalCodeTest extends TestCase
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

    private function makeRenewalCoupon($course, $tenant = null): Coupon
    {
        $code = 'E2E-RENEW-'.strtoupper(uniqid());
        $this->createdCodes[] = $code;

        return $this->onAdmin(fn () => Coupon::create([
            'code' => $code,
            'course_id' => $course->id,
            'is_renewal' => true,
            'tenant_id' => $tenant?->id,
        ]));
    }

    public function test_superadmin_can_generate_a_renewal_code_for_a_course(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $course = $this->existingCourse('SRC');

        $response = $this->actingAs($superadmin)->post('/superadmin/coupons', [
            'target' => 'renewal:'.$course->id,
            'tenant_id' => $tenant->id,
            'quantity' => 1,
        ]);

        $response->assertRedirect();
        // Bug-Review M6 (Folgefehler): die App-Verbindung sieht einen
        // fremden Mandanten-Coupon ohne gesetzten Tenant-Kontext (RLS)
        // nicht -- diese Prüfung muss deshalb über die Owner-Verbindung
        // laufen.
        $this->onAdmin(function () use ($tenant, $course) {
            $coupon = Coupon::where('tenant_id', $tenant->id)->where('course_id', $course->id)->first();
            $this->assertNotNull($coupon);
            $this->createdCodes[] = $coupon->code;
            $this->assertTrue($coupon->is_renewal);
        });

        $index = $this->actingAs($superadmin)->get('/superadmin/coupons?tenant_id='.$tenant->id);
        $index->assertOk();
        $index->assertSee('Verlängerung');
    }

    public function test_ablauf_1_a_learner_redeeming_a_renewal_code_extends_their_expired_entitlement(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'course_id' => $course->id,
            'status' => 'active',
            'source_type' => 'coupon',
            'valid_from' => now()->subDays(400),
            'valid_until' => now()->subDays(35),
        ]));

        $coupon = $this->makeRenewalCoupon($course, $tenant);

        $response = $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $coupon->code]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('courses.show', $course));

        $entitlement = $this->asTenant($tenant, fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->where('course_id', $course->id)->firstOrFail());
        $this->assertSame('active', $entitlement->status);
        $this->assertTrue($entitlement->valid_until->isFuture());
        $this->assertTrue($entitlement->valid_until->between(now()->addDays(364), now()->addDays(366)));

        $access = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");
        $access->assertOk();
    }

    public function test_renewal_extends_from_the_existing_expiry_not_from_today_when_still_valid(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $futureExpiry = now()->addDays(30);
        $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'course_id' => $course->id,
            'status' => 'active',
            'source_type' => 'coupon',
            'valid_from' => now(),
            'valid_until' => $futureExpiry,
        ]));

        $coupon = $this->makeRenewalCoupon($course, $tenant);
        $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $coupon->code])
            ->assertSessionHasNoErrors();

        $entitlement = $this->asTenant($tenant, fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->where('course_id', $course->id)->firstOrFail());

        // 30 Tage Restlaufzeit + 365 Tage Verlängerung, nicht nur 365 ab heute.
        $this->assertTrue($entitlement->valid_until->between($futureExpiry->copy()->addDays(364), $futureExpiry->copy()->addDays(366)));
    }

    public function test_ablauf_2_an_admin_can_assign_a_renewal_code_directly_to_a_participant(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'course_id' => $course->id,
            'status' => 'active',
            'source_type' => 'coupon',
            'valid_from' => now()->subDays(400),
            'valid_until' => now()->subDays(10),
        ]));

        $coupon = $this->makeRenewalCoupon($course, $tenant);

        $response = $this->actingAsInTenant($admin, $tenant)->post("/admin/coupons/{$coupon->id}/assign", [
            'user_id' => $learner->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->asTenant($tenant, function () use ($tenant, $learner, $course, $coupon) {
            $entitlement = Entitlement::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
                ->where('course_id', $course->id)->firstOrFail();
            $this->assertTrue($entitlement->valid_until->isFuture());

            $this->assertDatabaseHas('coupon', ['id' => $coupon->id, 'redeemed_by_user_id' => $learner->id]);
        });
    }

    /**
     * Bug-Review N5: valid_until=null bedeutet unbefristeten Zugang
     * (siehe Entitlement::isCurrentlyValid()) -- redeemForRenewal()
     * berechnete bislang unbedingt ein neues valid_until = now()+
     * course_validity_days, auch wenn das bestehende Entitlement gar kein
     * valid_until (also unbefristeten Zugang) hatte. Ein Verlängerungscode
     * VERKÜRZTE einen unbefristeten Zugang damit auf nur noch 1 Jahr,
     * statt ihn nur zurückzubringen bzw. zu verlängern.
     */
    public function test_redeeming_a_renewal_code_does_not_shorten_an_unlimited_entitlement(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'course_id' => $course->id,
            'status' => 'suspended',
            'source_type' => 'coupon',
            'valid_from' => now()->subDays(400),
            'valid_until' => null,
        ]));

        $coupon = $this->makeRenewalCoupon($course, $tenant);

        $response = $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $coupon->code]);

        $response->assertSessionHasNoErrors();

        $entitlement = $this->asTenant($tenant, fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->where('course_id', $course->id)->firstOrFail());
        $this->assertSame('active', $entitlement->status);
        $this->assertNull($entitlement->valid_until, 'Unbefristeter Zugang wurde durch den Verlängerungscode befristet.');
    }

    public function test_renewal_creates_a_fresh_entitlement_when_the_course_was_never_booked(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $coupon = $this->makeRenewalCoupon($course, $tenant);

        $response = $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $coupon->code]);

        $response->assertSessionHasNoErrors();
        $this->asTenant($tenant, function () use ($tenant, $learner, $course) {
            $this->assertDatabaseHas('entitlement', [
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'course_id' => $course->id,
                'status' => 'active',
                'source_type' => 'coupon_renewal',
            ]);
        });
    }

    public function test_coupon_type_filter_shows_only_renewal_codes_in_admin_tab(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');
        $renewal = $this->makeRenewalCoupon($course, $tenant);
        $this->createdCodes[] = 'E2E-NORMAL-'.strtoupper(uniqid());
        $normal = $this->onAdmin(fn () => Coupon::create([
            'code' => end($this->createdCodes),
            'course_id' => $course->id,
            'tenant_id' => $tenant->id,
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=coupons&coupon_type=renewal');

        $response->assertOk();
        $response->assertSee($renewal->code);
        $response->assertDontSee($normal->code);
    }
}
