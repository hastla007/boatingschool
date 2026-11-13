<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\AccountInvitation;
use App\Notifications\CouponRedeemed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Regressionstests zu Review-Runde 8 (Workflows Ende zu Ende: Einladung, Zugang, Datenschutz, Betrieb). */
class ReviewRoundEightFixesTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    public function test_a_new_participant_gets_a_school_invitation_and_a_never_activated_one_can_get_a_fresh_link(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant('Einladungs Schule');
        $admin = $this->createTenantUser($tenant, 'owner');
        $email = 'neu-'.Str::lower(Str::random(8)).'@example.test';

        $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', ['name' => 'Neu Ling', 'email' => $email])->assertRedirect();
        $user = $this->onAdmin(fn () => User::where('email', $email)->firstOrFail());
        $this->createdUserIds[] = $user->id;
        Notification::assertSentTo($user, AccountInvitation::class, fn ($n) => str_contains($n->url, 'invite=1')
            && str_contains($n->toMail($user)->subject, 'Einladungs Schule'));

        // Erneut einladen = neuer Link (statt „ist bereits Teilnehmer“).
        // Kein Konto-Orakel: wie bei Bestandskonten höchstens eine Mail je
        // Adresse und Tag -- am selben Tag also „heute schon eingeladen“
        // (früher: bis zu drei neue Links, was eine neue Adresse von einer
        // mit bestehendem Konto unterscheidbar machte).
        $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', ['name' => 'Neu Ling', 'email' => $email])
            ->assertSessionHas('status', 'Diese Person wurde heute schon eingeladen; die Einladung ist weiter gültig.');
        Notification::assertSentToTimes($user, AccountInvitation::class, 1);

        // Am nächsten Tag (Drossel abgelaufen) gibt es einen neuen Link.
        RateLimiter::clear('invite-mail:'.$tenant->id.':'.sha1(mb_strtolower($email)));
        $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', ['name' => 'Neu Ling', 'email' => $email])
            ->assertSessionHas('status', 'Einladung verschickt.');
        Notification::assertSentToTimes($user, AccountInvitation::class, 2);
        $this->asTenant($tenant, fn () => $this->assertSame(1, AuditLog::where('tenant_id', $tenant->id)->where('action', 'participant.invite_resent')->count()));

        // Die Liste zeigt die Person unter „Offene Einladungen“ mit „erneut senden“.
        $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=participants')
            ->assertSee('Offene Einladungen')->assertSee('eingeladen, noch nicht angemeldet')->assertSee('Einladung erneut senden');
    }

    public function test_a_suspended_learner_reaches_the_data_rights_page_and_can_export(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => TenantUser::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->update(['status' => 'suspended']));

        $this->actingAsInTenant($learner, $tenant)->get('/dashboard')->assertRedirect(route('profile.data-rights'));
        $this->actingAsInTenant($learner, $tenant)->get('/meine-daten')->assertOk()->assertSee('Konto löschen');
        $export = $this->actingAsInTenant($learner, $tenant)->get('/profile/export-data')->assertOk();
        $this->assertStringContainsString($learner->email, $export->getContent());
        $this->onAdmin(fn () => $this->assertTrue(AuditLog::where('action', 'profile.export')->where('entity_id', $learner->id)->exists()));
    }

    public function test_expired_access_shows_a_way_back_instead_of_a_dead_end(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);
        $this->onAdmin(fn () => Entitlement::whereKey($entitlement->id)->update(['valid_from' => now()->subDays(30), 'valid_until' => now()->subDay()]));

        $this->actingAsInTenant($learner, $tenant)->get('/dashboard')
            ->assertSee('Dein Kurszugang ist abgelaufen.')->assertSee(route('coupons.redeem'), false);
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}")
            ->assertForbidden()->assertSee('Dein Zugang zu diesem Kurs ist abgelaufen')->assertSee('Code einlösen');
    }

    public function test_unlocking_by_the_school_mails_the_learner(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->onAdmin(fn () => Coupon::create(['tenant_id' => $tenant->id, 'course_id' => $course->id, 'code' => 'R8-'.Str::upper(Str::random(10))]));

        $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', ['user_id' => $learner->id, 'course_id' => $course->id])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($learner, CouponRedeemed::class);
    }

    public function test_the_open_coupon_filter_lists_only_redeemable_codes(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');
        $this->onAdmin(function () use ($tenant, $course) {
            Coupon::create(['tenant_id' => $tenant->id, 'course_id' => $course->id, 'code' => 'R8-OPEN-CODE']);
            Coupon::create(['tenant_id' => $tenant->id, 'course_id' => $course->id, 'code' => 'R8-VOID-CODE', 'voided_at' => now()]);
            Coupon::create(['tenant_id' => $tenant->id, 'course_id' => $course->id, 'code' => 'R8-OLD-CODE', 'expires_at' => now()->subDay()]);
        });

        $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=coupons&coupon_status=open')
            ->assertSee('R8-OPEN-CODE')->assertDontSee('R8-VOID-CODE')->assertDontSee('R8-OLD-CODE');
        $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=coupons&coupon_status=voided')
            ->assertSee('R8-VOID-CODE')->assertDontSee('R8-OPEN-CODE');
    }

    public function test_a_failed_expiry_reminder_is_retried_on_the_next_run(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $entitlement = $this->grantEntitlement($tenant, $learner, $this->existingCourse('SRC'));
        $this->onAdmin(fn () => Entitlement::whereKey($entitlement->id)->update(['valid_until' => now()->addDays(3), 'expiry_reminded_at' => null]));

        Notification::shouldReceive('send')->andThrow(new RuntimeException('SMTP down'));
        $this->assertSame(1, Artisan::call('app:remind-expiring-entitlements'));
        $this->onAdmin(fn () => $this->assertNull(Entitlement::find($entitlement->id)->expiry_reminded_at));
    }

    public function test_the_school_can_delete_a_participants_data_after_typing_the_confirmation(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');

        $this->actingAsInTenant($admin, $tenant)->delete("/admin/participants/{$learner->id}/data", ['confirm' => 'ja'])->assertSessionHasErrors('confirm');
        $this->onAdmin(fn () => $this->assertTrue(TenantUser::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->exists()));

        $this->actingAsInTenant($admin, $tenant)->delete("/admin/participants/{$learner->id}/data", ['confirm' => 'LÖSCHEN'])->assertRedirect();
        $this->onAdmin(fn () => $this->assertFalse(TenantUser::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->exists()));
    }

    public function test_never_activated_accounts_are_pruned_but_not_those_with_a_course_or_a_team_role(): void
    {
        $tenant = $this->createTestTenant();
        $stale = $this->createTenantUser($tenant, 'learner', ['status' => 'invited']);
        $withCourse = $this->createTenantUser($tenant, 'learner', ['status' => 'invited']);
        $this->grantEntitlement($tenant, $withCourse, $this->existingCourse('SRC'));
        $owner = $this->createTenantUser($tenant, 'owner', ['status' => 'invited']);
        $fresh = $this->createTenantUser($tenant, 'learner', ['status' => 'invited']);
        $this->onAdmin(function () use ($stale, $withCourse, $owner) {
            User::whereIn('id', [$stale->id, $withCourse->id, $owner->id])->update(['created_at' => now()->subDays(120)]);
            // Review-Runde 11: eine frische Mitgliedschaft schützt das Konto -- hier ist auch sie alt.
            TenantUser::whereIn('user_id', [$stale->id, $withCourse->id, $owner->id])->update(['created_at' => now()->subDays(120)]);
        });

        Artisan::call('app:prune-personal-data');

        $this->onAdmin(function () use ($stale, $withCourse, $owner, $fresh) {
            $this->assertNull(User::find($stale->id));
            $this->assertNotNull(User::find($withCourse->id));
            $this->assertNotNull(User::find($owner->id));
            $this->assertNotNull(User::find($fresh->id));
        });
    }

    public function test_the_support_email_resend_shows_a_readable_message(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $this->onAdmin(fn () => $tenant->branding()->update(['support_email' => 'hilfe@example.test', 'support_email_verified_at' => null]));

        $response = $this->actingAsInTenant($admin, $tenant)->post(route('admin.branding.support-email.send'));

        $response->assertSessionHas('status', fn ($s) => ! str_contains($s, 'link-sent'));
    }
}
