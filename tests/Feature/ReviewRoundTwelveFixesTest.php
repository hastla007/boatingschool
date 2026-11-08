<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\ExamPaper;
use App\Models\ExamSession;
use App\Models\User;
use App\Notifications\AccountInvitation;
use App\Notifications\TwoFactorResetBySupport;
use App\Services\AccountSetupLink;
use App\Support\Totp;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Regressionstests zu Review-Runde 12. */
class ReviewRoundTwelveFixesTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    public function test_email_addresses_are_unique_regardless_of_case(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->onAdmin(fn () => DB::table('app_user')->insert([
            'email' => strtoupper($user->email), 'display_name' => 'Doppelt', 'password' => 'x', 'status' => 'active',
        ]));
    }

    public function test_two_factor_can_only_be_enabled_with_a_verified_address(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => DB::table('app_user')->where('id', $user->id)->update(['email_verified_at' => null]));
        $user = $this->onAdmin(fn () => User::find($user->id));

        $this->actingAsInTenant($user, $tenant)->post('/two-factor/enable', ['password' => 'password'])->assertRedirect(route('verification.notice'));
        $this->assertNull($this->onAdmin(fn () => User::find($user->id)->two_factor_secret));
    }

    public function test_a_password_reset_as_first_mailbox_proof_clears_foreign_two_factor(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => User::findOrFail($user->id)->forceFill([
            'email_verified_at' => null, 'two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now(),
        ])->save());
        $token = $this->onAdmin(fn () => Password::broker()->createToken(User::find($user->id)));

        $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/reset-password', [
            'token' => $token, 'email' => $user->email, 'password' => 'neues-passwort-123', 'password_confirmation' => 'neues-passwort-123',
        ])->assertSessionHasNoErrors();

        $fresh = $this->onAdmin(fn () => User::find($user->id));
        $this->assertFalse($fresh->hasTwoFactor());
        $this->assertNotNull($fresh->email_verified_at);
    }

    public function test_the_password_step_of_a_two_factor_login_keeps_remembered_devices(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'owner', ['password' => Hash::make('geheim123!')]);
        $this->onAdmin(fn () => User::findOrFail($user->id)->forceFill([
            'two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now(), 'remember_token' => 'gemerkt-r12',
        ])->save());

        $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/login', ['email' => $user->email, 'password' => 'geheim123!'])
            ->assertRedirect(route('two-factor.challenge'));

        $this->assertSame('gemerkt-r12', $this->onAdmin(fn () => User::find($user->id)->remember_token));
    }

    public function test_a_suspended_account_cannot_finish_a_pending_two_factor_login(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'owner', ['password' => Hash::make('geheim123!')]);
        $secret = Totp::generateSecret();
        $this->onAdmin(fn () => User::findOrFail($user->id)->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save());

        $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/login', ['email' => $user->email, 'password' => 'geheim123!']);
        $this->onAdmin(fn () => DB::table('app_user')->where('id', $user->id)->update(['status' => 'suspended']));
        $this->post('/two-factor-challenge', ['code' => Totp::at($secret, time())]);

        $this->assertGuest();
    }

    public function test_login_attempts_are_also_limited_per_account_across_addresses(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['password' => Hash::make('geheim123!')]);
        RateLimiter::clear('login-account:'.$user->email);

        foreach (range(1, 20) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])->withSession(['dev_tenant_slug' => $tenant->slug])
                ->post('/login', ['email' => $user->email, 'password' => 'falsch']);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])->withSession(['dev_tenant_slug' => $tenant->slug])
            ->post('/login', ['email' => $user->email, 'password' => 'geheim123!'])->assertSessionHasErrors('email');
        $this->assertGuest();
        RateLimiter::clear('login-account:'.$user->email);
    }

    public function test_reopening_a_paper_during_the_navigation_self_assessment_continues_it(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);
        $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->where('paper_number', 3)->firstOrFail());
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}")->assertRedirect();
        $session = $this->asTenant($tenant, fn () => ExamSession::where('user_id', $learner->id)->firstOrFail());
        $this->travelTo($session->started_at->copy()->addSeconds(3590));
        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/navigation", ['answers' => []])->assertRedirect();

        $this->travelTo($session->started_at->copy()->addSeconds(3700));
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}")
            ->assertRedirect(route('exam.show', $session));
        $this->assertSame('running', $this->onAdmin(fn () => ExamSession::find($session->id)->status));
    }

    public function test_team_members_get_formal_account_mails_and_school_names_are_escaped(): void
    {
        $tenant = $this->createTestTenant('Segelschule *Möwe* [Co](https://evil.test)');
        $owner = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');

        $this->assertStringContainsString('Ihre Zwei-Faktor', (new TwoFactorResetBySupport)->toMail($owner)->subject);
        $this->assertStringContainsString('Deine Zwei-Faktor', (new TwoFactorResetBySupport)->toMail($learner)->subject);

        $html = (string) (new AccountInvitation($tenant, 'staff', 'https://example.test/x'))->toMail($learner)->render();
        $this->assertStringContainsString('als Mitarbeiter', $html);
        $this->assertStringNotContainsString('href="https://evil.test"', $html);
    }

    public function test_a_coupon_typed_with_spaces_is_found(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $code = 'R12A-BCDE-FGHJ-KLMN';
        $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        try {
            $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => 'r12a bcde fghj klmn'])->assertSessionHasNoErrors();
            $this->assertTrue($this->onAdmin(fn () => Coupon::where('code', $code)->first()->isRedeemed()));
        } finally {
            $this->onAdmin(function () use ($code) {
                Entitlement::where('source_reference', $code)->delete();
                Coupon::where('code', $code)->delete();
            });
        }
    }

    public function test_an_expired_invitation_link_says_so_when_opened(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['status' => 'invited']);

        $this->withSession(['dev_tenant_slug' => $tenant->slug])
            ->get('/reset-password/ungueltiger-token?email='.urlencode($user->email).'&invite=1')
            ->assertOk()->assertSee('abgelaufen oder wurde schon benutzt');

        $url = $this->onAdmin(fn () => app(AccountSetupLink::class)->url($user, $tenant));
        $path = preg_replace('#^/'.preg_quote($tenant->slug, '#').'(?=/)#', '', parse_url($url, PHP_URL_PATH));
        $this->withSession(['dev_tenant_slug' => $tenant->slug])->get($path.'?'.parse_url($url, PHP_URL_QUERY))
            ->assertOk()->assertDontSee('abgelaufen oder wurde schon benutzt');
    }

    public function test_deleting_the_own_account_confirms_it(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $this->actingAsInTenant($user, $tenant)->delete('/profile', ['password' => 'password'])
            ->assertRedirect(route('login'))->assertSessionHas('status', fn ($s) => str_contains($s, 'gelöscht'));
    }
}
