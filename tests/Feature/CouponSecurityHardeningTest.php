<?php

namespace Tests\Feature;

use App\Models\Coupon;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview M8: der Import/das Einlösen hatten kein Limit, obwohl
 * die Rückmeldung ("bereits eingelöst" vs. "unbekannt") als Oracle für
 * Code-Existenz dient -- und str_shuffle() (Mt19937, kein CSPRNG) erzeugte
 * Codes mit nur ~59 Bit Entropie.
 */
class CouponSecurityHardeningTest extends TestCase
{
    use InteractsWithTenants;

    public function test_redeeming_coupons_is_rate_limited(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        for ($i = 0; $i < 10; $i++) {
            $response = $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => 'NOPE-'.$i]);
            $this->assertNotEquals(429, $response->status());
        }

        $response = $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => 'ONE-MORE']);

        $response->assertStatus(429);
    }

    public function test_importing_coupons_is_rate_limited(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');

        for ($i = 0; $i < 10; $i++) {
            $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/coupons/import', ['codes' => 'NOPE-'.$i]);
            $this->assertNotEquals(429, $response->status());
        }

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/coupons/import', ['codes' => 'ONE-MORE']);

        $response->assertStatus(429);
    }

    public function test_importing_more_than_500_codes_is_rejected(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');

        $codes = implode(',', array_map(fn ($i) => "CODE-{$i}", range(1, 501)));

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/coupons/import', ['codes' => $codes]);

        $response->assertStatus(422);
    }

    public function test_generated_codes_are_at_least_sixteen_characters_from_the_safe_alphabet(): void
    {
        $code = Coupon::generateUniqueCode();

        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code);
        $this->assertGreaterThanOrEqual(16, strlen(str_replace('-', '', $code)));
    }

    public function test_generated_codes_are_unique_across_many_calls(): void
    {
        $codes = $this->onAdmin(function () {
            $codes = [];
            for ($i = 0; $i < 50; $i++) {
                $codes[] = Coupon::generateUniqueCode();
            }

            return $codes;
        });

        $this->assertCount(50, array_unique($codes));
    }
}
