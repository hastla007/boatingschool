<?php

namespace Tests\Feature;

use App\Models\CommerceShopAccount;
use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\Product;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Review-Runde 14: Superadmin-2FA, Löschung von Shopdaten, Bündel-Codes, Code-Import. */
class ReviewRoundFourteenTest extends TestCase
{
    use InteractsWithTenants;

    private array $productIds = [];

    private array $shopAccountIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            CommerceShopAccount::whereIn('id', $this->shopAccountIds)->delete();
            Coupon::withoutGlobalScopes()->whereHas('optionProducts', fn ($q) => $q->whereIn('product.id', $this->productIds))->each(fn (Coupon $c) => $c->optionProducts()->detach());
            Product::whereIn('id', $this->productIds)->delete();
        });
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    public function test_the_superadmin_area_requires_two_factor_login(): void
    {
        config(['auth.superadmin_two_factor' => true]);
        $superadmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)->get('/superadmin')->assertRedirect(route('two-factor.show'));
        // Die Einrichtung selbst bleibt erreichbar.
        $this->actingAs($superadmin)->get(route('two-factor.show'))->assertOk();

        $this->onAdmin(fn () => User::findOrFail($superadmin->id)->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save());
        $this->actingAs($this->onAdmin(fn () => User::findOrFail($superadmin->id)))->get('/superadmin')->assertOk();
    }

    public function test_deleting_an_account_anonymises_its_shop_accounts(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $this->createTenantUser($tenant, 'owner');
        $admin = $this->createTenantUser($tenant, 'admin');
        $account = $this->onAdmin(fn () => CommerceShopAccount::create([
            'shop_id' => 'test-'.Str::lower(Str::random(8)), 'shop_customer_id' => '77', 'user_id' => $admin->id, 'tenant_id' => $tenant->id,
            'status' => 'linked', 'email' => $admin->email, 'contact_name' => 'Kim Meer', 'company_name' => 'Segelschule Kim', 'vat_id' => 'DE123456789', 'linked_at' => now(),
        ]));
        $this->shopAccountIds[] = $account->id;

        $this->actingAs($superadmin)->delete("/superadmin/users/{$admin->id}", ['confirm_email' => $admin->email])->assertRedirect();

        $fresh = $this->onAdmin(fn () => $account->fresh());
        $this->assertNull($fresh->user_id);
        $this->assertSame('revoked', $fresh->status);
        $this->assertNull($fresh->contact_name);
        $this->assertNull($fresh->vat_id);
        $this->assertStringNotContainsString($admin->email, $fresh->email);
    }

    public function test_manual_unlock_uses_plain_course_codes_before_bundles(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $product = $this->onAdmin(fn () => Product::create(['code' => 'BOX-'.Str::upper(Str::random(6)), 'name' => 'Box Test', 'active' => true]));
        $this->productIds[] = $product->id;

        // Der Bündel-Code ist älter -- er wird trotzdem nicht verbraucht.
        $bundle = $this->onAdmin(function () use ($course, $tenant, $product) {
            $coupon = Coupon::create(['code' => Coupon::generateUniqueCode(), 'course_id' => $course->id, 'tenant_id' => $tenant->id]);
            $coupon->forceFill(['created_at' => now()->subDay()])->save();
            $coupon->optionProducts()->attach($product->id);

            return $coupon;
        });
        $plain = $this->onAdmin(fn () => Coupon::create(['code' => Coupon::generateUniqueCode(), 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        $this->actingAsInTenant($owner, $tenant)->post(route('admin.entitlements.store'), ['user_id' => $learner->id, 'course_id' => $course->id])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($this->onAdmin(fn () => $plain->fresh()->redeemed_at));
        $this->assertNull($this->onAdmin(fn () => $bundle->fresh()->redeemed_at));
        $this->assertTrue($this->onAdmin(fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->exists()));
    }

    public function test_code_import_accepts_the_same_spellings_as_redemption(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $coupon = $this->onAdmin(fn () => Coupon::create(['code' => Coupon::generateUniqueCode(), 'course_id' => $this->existingCourse('SRC')->id]));

        $compact = strtolower(str_replace('-', '', $coupon->code));
        $this->actingAsInTenant($admin, $tenant)->post('/admin/coupons/import', ['codes' => $compact])->assertRedirect();

        $this->assertSame($tenant->id, $this->onAdmin(fn () => $coupon->fresh()->tenant_id));
    }
}
