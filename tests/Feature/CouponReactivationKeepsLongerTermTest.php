<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\Tenant;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Gutschein verkürzt gesperrten Zugang): ein Kurscode auf ein
 * gesperrtes Entitlement setzte valid_until bisher immer auf heute +
 * Kurslaufzeit -- eine längere Restlaufzeit ging dabei verloren. Es gilt
 * jetzt das spätere der beiden Enddaten.
 */
class CouponReactivationKeepsLongerTermTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCodes = [];

    protected function tearDown(): void
    {
        $this->onAdmin(fn () => Coupon::whereIn('code', $this->createdCodes)->delete());

        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_a_longer_remaining_term_survives_the_reactivation(): void
    {
        [$tenant, $entitlement] = $this->redeemOnSuspended(now()->addYears(5));

        $validUntil = $this->onAdmin(fn () => Entitlement::find($entitlement->id)->valid_until);
        $this->assertSame('active', $this->onAdmin(fn () => Entitlement::find($entitlement->id)->status));
        $this->assertTrue($validUntil->greaterThan(now()->addYears(5)->subMinute()), "valid_until wurde auf {$validUntil} verkürzt.");
    }

    public function test_a_shorter_remaining_term_is_extended_to_the_course_validity(): void
    {
        [$tenant, $entitlement] = $this->redeemOnSuspended(now()->addDays(3));

        $validUntil = $this->onAdmin(fn () => Entitlement::find($entitlement->id)->valid_until);
        $expected = now()->addDays($tenant->branding->course_validity_days);
        $this->assertTrue(abs($validUntil->diffInSeconds($expected)) < 120, "valid_until {$validUntil} statt ca. {$expected}.");
    }

    /** @return array{0: Tenant, 1: Entitlement} */
    private function redeemOnSuspended(\DateTimeInterface $validUntil): array
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $entitlement = $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'course_id' => $course->id,
            'status' => 'suspended',
            'source_type' => 'coupon',
            'valid_from' => now()->subDays(30),
            'valid_until' => $validUntil,
        ]));

        $code = 'E2E-SUSP-TERM-'.strtoupper(uniqid());
        $this->createdCodes[] = $code;
        $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $code])->assertSessionHasNoErrors();

        return [$tenant, $entitlement];
    }
}
