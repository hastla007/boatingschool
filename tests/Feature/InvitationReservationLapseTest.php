<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\TenantInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Review #95/#96: ein beim Einladen angegebener Code ist VALID_DAYS für die
 * Einladung reserviert. Danach darf die Schule ihn für eine andere Einladung
 * vormerken -- ein später doch noch angenommener alter Link (z. B. über
 * „Passwort vergessen“ beim nie aktivierten Konto) darf ihr den Code dann
 * nicht mehr wegnehmen.
 */
class InvitationReservationLapseTest extends TestCase
{
    use InteractsWithTenants;

    private const CODE = 'E2E-RESERVATION-LAPSE';

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            Coupon::where('code', self::CODE)->delete();
            foreach (User::where('email', 'like', 'reservierung-%@example.test')->get() as $user) {
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                DB::table('account_setup_tokens')->where('email', $user->email)->delete();
                $this->createdUserIds[] = $user->id;
            }
        });
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    private function setPassword(User $user, string $tenantSlug): void
    {
        $token = Password::broker('invites')->createToken($user);
        $this->app['auth']->forgetGuards();
        $this->withSession(['dev_tenant_slug' => $tenantSlug])->post('/reset-password', [
            'invite' => '1', 'token' => $token, 'email' => $user->email, 'age_confirmed' => '1',
            'password' => 'Ein-sicheres-Passwort-2026', 'password_confirmation' => 'Ein-sicheres-Passwort-2026',
        ])->assertSessionHasNoErrors();
    }

    public function test_a_lapsed_reservation_does_not_take_the_code_from_a_newer_open_invitation(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');
        $this->onAdmin(fn () => Coupon::create(['code' => self::CODE, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));
        $first = 'reservierung-alt-'.uniqid().'@example.test';
        $second = 'reservierung-neu-'.uniqid().'@example.test';

        $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', ['name' => 'Erste Person', 'email' => $first, 'code' => self::CODE])
            ->assertSessionHasNoErrors();
        // Die erste Einladung ist abgelaufen (älter als VALID_DAYS), die Reservierung damit frei.
        $this->onAdmin(fn () => TenantInvitation::where('tenant_id', $tenant->id)->where('email', $first)->update(['created_at' => now()->subDays(10)]));

        // Die Schule merkt denselben Code für eine zweite Person vor.
        $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', ['name' => 'Zweite Person', 'email' => $second, 'code' => self::CODE])
            ->assertSessionHasNoErrors();
        $this->assertSame(self::CODE, $this->onAdmin(fn () => TenantInvitation::where('tenant_id', $tenant->id)->where('email', $second)->value('coupon_code')));

        // Die erste Person aktiviert ihr Konto doch noch: Mitglied ja, Code nein.
        $firstUser = $this->onAdmin(fn () => User::where('email', $first)->firstOrFail());
        $this->setPassword($firstUser, $tenant->slug);
        $this->assertFalse($this->onAdmin(fn () => Coupon::where('code', self::CODE)->first()->isRedeemed()), 'Code bleibt für die offene Einladung reserviert');
        $this->assertFalse($this->onAdmin(fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $firstUser->id)->exists()));

        // Die zweite Person bekommt ihn beim Annehmen.
        $secondUser = $this->onAdmin(fn () => User::where('email', $second)->firstOrFail());
        $this->setPassword($secondUser, $tenant->slug);
        $coupon = $this->onAdmin(fn () => Coupon::where('code', self::CODE)->first());
        $this->assertTrue($coupon->isRedeemed());
        $this->assertSame($secondUser->id, $coupon->redeemed_by_user_id);
    }

    public function test_a_lapsed_reservation_is_still_redeemed_when_no_one_else_holds_the_code(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');
        $this->onAdmin(fn () => Coupon::create(['code' => self::CODE, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));
        $email = 'reservierung-spaet-'.uniqid().'@example.test';

        $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', ['name' => 'Späte Person', 'email' => $email, 'code' => self::CODE])
            ->assertSessionHasNoErrors();
        $this->onAdmin(fn () => TenantInvitation::where('tenant_id', $tenant->id)->where('email', $email)->update(['created_at' => now()->subDays(10)]));

        $user = $this->onAdmin(fn () => User::where('email', $email)->firstOrFail());
        $this->setPassword($user, $tenant->slug);

        $this->assertSame($user->id, $this->onAdmin(fn () => Coupon::where('code', self::CODE)->first())->redeemed_by_user_id);
    }
}
