<?php

namespace Tests\Feature\Auth;

use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\TenantBranding;
use App\Models\User;
use App\Support\RegistrationCouponNotice;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Registrierung: Schalter „Selbstregistrierung erlauben“ je Schule und
 * optionaler Coupon-Code im Registrierungsformular.
 */
class SelfRegistrationSettingsTest extends TestCase
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

    private function disableSelfRegistration($tenant): void
    {
        $this->onAdmin(fn () => TenantBranding::where('tenant_id', $tenant->id)->update(['self_registration_enabled' => false]));
    }

    private function register($tenant, string $email, array $extra = [])
    {
        return $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/register', [
            'name' => 'Neu Registriert',
            'email' => $email,
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
            ...$extra,
        ]);
    }

    private function trackUser(string $email): ?User
    {
        return $this->onAdmin(function () use ($email) {
            $user = User::where('email', $email)->first();
            if ($user) {
                $this->createdUserIds[] = $user->id;
            }

            return $user;
        });
    }

    public function test_self_registration_is_allowed_by_default(): void
    {
        $tenant = $this->createTestTenant();

        $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/register')
            ->assertOk()->assertSee('name="coupon_code"', false)->assertDontSee('nur auf Einladung');
        $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/login')
            ->assertOk()->assertSee('Jetzt registrieren');
    }

    public function test_when_disabled_the_register_page_explains_invitations_and_login_hides_the_link(): void
    {
        $tenant = $this->createTestTenant();
        $this->disableSelfRegistration($tenant);

        $page = $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/register');
        $page->assertOk();
        $page->assertSee('werden Konten nur auf Einladung der Bootsschule angelegt');
        $page->assertDontSee('name="password_confirmation"', false);

        $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/login')
            ->assertOk()->assertDontSee('Jetzt registrieren');
    }

    public function test_when_disabled_posting_the_form_is_rejected_and_creates_no_account(): void
    {
        $tenant = $this->createTestTenant();
        $this->disableSelfRegistration($tenant);
        $email = 'gesperrt-'.uniqid().'@example.test';

        $this->register($tenant, $email)->assertForbidden();

        $this->assertNull($this->trackUser($email));
    }

    public function test_admin_can_switch_self_registration_off_and_on_in_the_branding_tab(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $form = ['exam_readiness_threshold_percent' => 50];

        $this->actingAsInTenant($owner, $tenant)->get(route('admin.dashboard'))->assertSee('Selbstregistrierung erlauben');

        $this->actingAsInTenant($owner, $tenant)->patch(route('admin.branding.update'), $form + ['self_registration_enabled' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($this->onAdmin(fn () => TenantBranding::find($tenant->id)->self_registration_enabled));

        // Ein Formular ohne das Feld (ältere Seite) ändert den Schalter nicht.
        $this->actingAsInTenant($owner, $tenant)->patch(route('admin.branding.update'), $form)->assertSessionHasNoErrors();
        $this->assertFalse($this->onAdmin(fn () => TenantBranding::find($tenant->id)->self_registration_enabled));

        $this->actingAsInTenant($owner, $tenant)->patch(route('admin.branding.update'), $form + ['self_registration_enabled' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($this->onAdmin(fn () => TenantBranding::find($tenant->id)->self_registration_enabled));
    }

    public function test_a_valid_coupon_code_is_redeemed_right_after_registration_and_announced_after_login(): void
    {
        $tenant = $this->createTestTenant();
        $course = $this->existingCourse('SRC');
        $code = Coupon::generateUniqueCode();
        $this->createdCodes[] = $code;
        $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));
        $email = 'mit-code-'.uniqid().'@example.test';

        $response = $this->register($tenant, $email, ['coupon_code' => strtolower($code)]);

        $response->assertRedirect();
        $this->assertGuest();
        $user = $this->trackUser($email);
        $this->assertNotNull($user);
        $this->onAdmin(function () use ($tenant, $user, $course, $code) {
            $this->assertTrue(Entitlement::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('course_id', $course->id)->where('status', 'active')->exists());
            $this->assertSame($user->id, Coupon::where('code', $code)->value('redeemed_by_user_id'));
        });

        // Nach der Anmeldung (Login-Event) einmalig angezeigt.
        $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/login');
        event(new Login('web', $user, false));
        $notice = session(RegistrationCouponNotice::SESSION_KEY);
        $this->assertSame('success', $notice['type']);
        $this->assertStringContainsString($course->name, $notice['message']);

        $this->onAdmin(fn () => User::whereKey($user->id)->update(['email_verified_at' => now()]));
        $dashboard = $this->actingAsInTenant($user->fresh(), $tenant)
            ->withSession([RegistrationCouponNotice::SESSION_KEY => $notice])->get('/dashboard');
        $dashboard->assertOk();
        $dashboard->assertSee('Dein Coupon-Code aus der Registrierung wurde eingelöst');
    }

    public function test_an_invalid_coupon_code_does_not_block_registration(): void
    {
        $tenant = $this->createTestTenant();
        $email = 'falscher-code-'.uniqid().'@example.test';

        $response = $this->register($tenant, $email, ['coupon_code' => 'GIBT-ES-NICHT-1234']);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $user = $this->trackUser($email);
        $this->assertNotNull($user);

        $notice = Cache::get('registration-coupon-notice:'.$user->id);
        $this->assertSame('error', $notice['type']);
        $this->assertStringContainsString('ungültig', $notice['message']);
    }

    public function test_a_coupon_code_sent_with_an_existing_email_is_not_redeemed(): void
    {
        $tenant = $this->createTestTenant();
        $existing = $this->createTenantUser($tenant, 'learner', ['email' => 'schon-da-'.uniqid().'@example.test']);
        $course = $this->existingCourse('SRC');
        $code = Coupon::generateUniqueCode();
        $this->createdCodes[] = $code;
        $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        $this->register($tenant, $existing->email, ['coupon_code' => $code])->assertRedirect();

        $this->assertNull($this->onAdmin(fn () => Coupon::where('code', $code)->value('redeemed_by_user_id')));
        $this->assertNull(Cache::get('registration-coupon-notice:'.$existing->id));
    }
}
