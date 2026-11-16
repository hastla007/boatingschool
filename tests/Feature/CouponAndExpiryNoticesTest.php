<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CouponBatch;
use App\Models\Entitlement;
use App\Notifications\CouponRedeemed;
use App\Notifications\EntitlementExpiringSoon;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bestätigungsmail beim Einlösen, Zugangscodes sperren bzw. mit
 * Ablaufdatum, Vorwarnung vor dem Ablauf eines Kurszugangs.
 */
class CouponAndExpiryNoticesTest extends TestCase
{
    use InteractsWithTenants;

    private array $codes = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            $batchIds = Coupon::whereIn('code', $this->codes)->whereNotNull('batch_id')->pluck('batch_id');
            Coupon::whereIn('code', $this->codes)->delete();
            CouponBatch::withoutGlobalScopes()->whereIn('id', $batchIds)->delete();
        });
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function coupon(string $code, array $attributes = []): Coupon
    {
        $this->codes[] = $code;

        return $this->onAdmin(fn () => Coupon::create($attributes + ['code' => $code, 'course_id' => $this->existingCourse('SRC')->id]));
    }

    public function test_redeeming_a_code_sends_a_confirmation_mail(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->coupon('E2E-CONFIRM-0001', ['tenant_id' => $tenant->id]);

        $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => 'E2E-CONFIRM-0001'])->assertSessionHasNoErrors();

        Notification::assertSentTo($learner, CouponRedeemed::class, function ($notification) use ($learner) {
            $mail = $notification->toMail($learner);

            return $mail->subject === 'Dein Kurs ist freigeschaltet' && str_contains(implode(' ', $mail->introLines), 'SRC');
        });
    }

    public function test_voided_and_expired_codes_cannot_be_redeemed_imported_or_assigned(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $admin = $this->createTenantUser($tenant, 'owner');
        $this->coupon('E2E-VOID-0001', ['tenant_id' => $tenant->id, 'voided_at' => now()]);
        $this->coupon('E2E-EXP-0001', ['tenant_id' => $tenant->id, 'expires_at' => now()->subMinute()]);
        $this->coupon('E2E-VOID-FREE', ['voided_at' => now()]);

        foreach (['E2E-VOID-0001', 'E2E-EXP-0001'] as $code) {
            $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $code])
                ->assertSessionHasErrors(['code' => 'Dieser Code ist nicht mehr gültig.']);
        }
        $this->actingAsInTenant($admin, $tenant)->post('/admin/coupons/import', ['codes' => 'E2E-VOID-FREE']);
        $this->onAdmin(fn () => $this->assertNull(Coupon::where('code', 'E2E-VOID-FREE')->first()->tenant_id));

        $voided = $this->onAdmin(fn () => Coupon::where('code', 'E2E-VOID-0001')->first());
        $this->actingAsInTenant($admin, $tenant)->post("/admin/coupons/{$voided->id}/assign", ['user_id' => $learner->id])->assertNotFound();
        $this->assertSame(0, $this->asTenant($tenant, fn () => Entitlement::where('user_id', $learner->id)->count()));
    }

    public function test_the_superadmin_can_set_an_expiry_date_and_void_open_codes(): void
    {
        $superadmin = $this->createSuperAdmin();
        $course = $this->existingCourse('SRC');

        $this->actingAs($superadmin)->post('/superadmin/coupons', [
            'target' => 'course:'.$course->id,
            'quantity' => 2,
            'batch_label' => 'E2E-Charge-Ablauf',
            'expires_on' => now('Europe/Berlin')->addDays(30)->toDateString(),
        ])->assertSessionHasNoErrors();

        $batch = $this->onAdmin(fn () => Coupon::where('batch_label', 'E2E-Charge-Ablauf')->get());
        $this->codes = array_merge($this->codes, $batch->pluck('code')->all());
        $this->assertCount(2, $batch);
        $this->assertTrue($batch->every(fn ($c) => $c->expires_at?->isFuture() && $c->isUsable()));

        $redeemed = $this->coupon('E2E-VOID-REDEEMED', ['redeemed_at' => now()]);
        $ids = [...$batch->pluck('id')->all(), $redeemed->id];
        $this->actingAs($superadmin)->post('/superadmin/coupons/void', ['ids' => $ids])
            ->assertSessionHas('status', '2 Codes gesperrt.');

        $this->onAdmin(function () use ($batch, $redeemed) {
            $this->assertTrue(Coupon::whereIn('id', $batch->pluck('id'))->get()->every(fn ($c) => $c->isVoided() && $c->statusLabel() === 'gesperrt'));
            $this->assertNull($redeemed->fresh()->voided_at, 'eingelöste Codes bleiben unberührt');
        });
    }

    public function test_learners_are_warned_before_their_access_expires_once_per_expiry_date(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);
        $this->onAdmin(fn () => $entitlement->update(['valid_from' => now()->subMonth(), 'valid_until' => now()->addDays(10)]));

        $this->actingAsInTenant($learner, $tenant)->get('/courses')->assertOk()
            ->assertSee('Dein Zugang zu', false)->assertSee('läuft am', false);

        $this->artisan('app:remind-expiring-entitlements')->assertSuccessful();
        Notification::assertSentToTimes($learner, EntitlementExpiringSoon::class, 1);
        $this->artisan('app:remind-expiring-entitlements')->assertSuccessful();
        Notification::assertSentToTimes($learner, EntitlementExpiringSoon::class, 1);

        // Verlängert und später wieder kurz vor dem Ablauf: neue Erinnerung.
        $this->onAdmin(fn () => Entitlement::findOrFail($entitlement->id)->update(['valid_until' => now()->addDays(5)]));
        $this->artisan('app:remind-expiring-entitlements')->assertSuccessful();
        Notification::assertSentToTimes($learner, EntitlementExpiringSoon::class, 2);

        // Weit vor dem Ablauf: keine Warnung.
        $this->onAdmin(fn () => Entitlement::findOrFail($entitlement->id)->update(['valid_until' => now()->addDays(60)]));
        $this->actingAsInTenant($learner, $tenant)->get('/courses')->assertOk()->assertDontSee('Dein Zugang zu', false);
    }
}
