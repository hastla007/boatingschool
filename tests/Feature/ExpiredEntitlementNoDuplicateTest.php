<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Entitlement;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Review-Runde 3 (Daten): ein Kurscode nach Ablauf reaktiviert den
 * abgelaufenen Zugang statt eine zweite Zeile anzulegen, und „Kurs
 * freischalten“ verbraucht danach keinen weiteren Code.
 */
class ExpiredEntitlementNoDuplicateTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->onAdmin(fn () => DB::table('audit_log')->whereIn('tenant_id', $this->createdTenantIds)->delete());
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    public function test_a_course_code_after_expiry_reactivates_the_existing_entitlement(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);
        $this->onAdmin(fn () => Entitlement::where('id', $entitlement->id)->update(['valid_from' => now()->subYear(), 'valid_until' => now()->subDay()]));
        Artisan::call('app:expire-entitlements');

        $codes = [];
        $this->onAdmin(function () use ($tenant, $course, &$codes) {
            foreach ([1, 2] as $i) {
                $codes[$i] = 'R3'.strtoupper(Str::random(10));
                Coupon::forceCreate(['code' => $codes[$i], 'course_id' => $course->id, 'tenant_id' => $tenant->id, 'is_renewal' => false]);
            }
        });

        try {
            $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $codes[1]])->assertSessionHasNoErrors();
            $rows = $this->onAdmin(fn () => Entitlement::where('user_id', $learner->id)->where('course_id', $course->id)->get());
            $this->assertCount(1, $rows);
            $this->assertSame('active', $rows->first()->status);
            $this->assertTrue($rows->first()->valid_until->isFuture());

            // „Kurs freischalten“ erkennt den aktiven Zugang: kein zweiter Code.
            $this->actingAsInTenant($owner, $tenant)->post('/admin/entitlements', ['user_id' => $learner->id, 'course_id' => $course->id]);
            $this->assertNull($this->onAdmin(fn () => Coupon::where('code', $codes[2])->value('redeemed_at')));
        } finally {
            $this->onAdmin(fn () => Coupon::whereIn('code', array_values($codes))->delete());
        }
    }
}
