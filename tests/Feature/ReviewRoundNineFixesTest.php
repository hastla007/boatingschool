<?php

namespace Tests\Feature;

use App\Models\PraxisExamAppointment;
use App\Models\PraxisFormSubmission;
use App\Models\Progress;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use App\Services\AccountSetupLink;
use App\Services\MasteryCalculator;
use App\Services\TenantOffboardingService;
use App\Support\TenantPath;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Regressionstests zu Review-Runde 9 (Rückschritte, Sicherheit, Lernlogik, Oberfläche). */
class ReviewRoundNineFixesTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    public function test_invite_links_have_their_own_table_and_survive_the_daily_prune(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['status' => 'invited']);

        $url = $this->onAdmin(fn () => app(AccountSetupLink::class)->url($user, $tenant));
        $this->onAdmin(function () use ($user) {
            $this->assertTrue(DB::table('account_setup_tokens')->where('email', $user->email)->exists());
            $this->assertFalse(DB::table('password_reset_tokens')->where('email', $user->email)->exists());
        });

        $this->travel(2)->hours();
        Artisan::call('app:prune-personal-data');
        $this->onAdmin(fn () => $this->assertTrue(DB::table('account_setup_tokens')->where('email', $user->email)->exists()));

        $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/reset-password', [
            'invite' => '1', 'token' => basename(parse_url($url, PHP_URL_PATH)), 'email' => $user->email,
            'password' => 'neues-passwort-123', 'password_confirmation' => 'neues-passwort-123',
        ])->assertSessionHasNoErrors();
    }

    public function test_a_normal_reset_token_does_not_become_a_seven_day_invite(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->onAdmin(fn () => Password::broker()->createToken($user));

        $this->travel(3)->hours();
        $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/reset-password', [
            'invite' => '1', 'token' => $token, 'email' => $user->email,
            'password' => 'neues-passwort-123', 'password_confirmation' => 'neues-passwort-123',
        ])->assertSessionHasErrors('email');
    }

    public function test_the_participant_csv_cannot_be_broken_out_of_with_a_backslash_quote(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $this->createTenantUser($tenant, 'learner', ['first_name' => 'x\\";=HYPERLINK("http://evil",1);"', 'last_name' => 'Muster']);

        $csv = $this->actingAsInTenant($admin, $tenant)->get('/admin/participants/export')->assertOk()->streamedContent();

        foreach (array_slice(preg_split('/\R/', trim(substr($csv, 3))), 1) as $line) {
            foreach (str_getcsv($line, ';', '"', '') as $cell) {
                $this->assertDoesNotMatchRegularExpression('/^\s*[=+\-@]/', $cell, "Zelle „{$cell}“ würde als Formel ausgeführt");
            }
        }
    }

    public function test_a_school_cannot_delete_a_superadmin_account(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $support = $this->createTenantUser($tenant, 'learner', ['is_superadmin' => true]);

        $this->actingAsInTenant($admin, $tenant)->delete("/admin/participants/{$support->id}/data", ['confirm' => 'LÖSCHEN'])
            ->assertSessionHasErrors('confirm');
        $this->onAdmin(fn () => $this->assertNotNull(User::find($support->id)));
    }

    public function test_repeated_team_invites_to_an_existing_account_send_one_mail_a_day(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $existing = $this->createTenantUser($this->createTestTenant('Andere'), 'learner');

        foreach (range(1, 3) as $i) {
            $this->actingAsInTenant($owner, $tenant)->post('/admin/team/invite', ['name' => 'Kollege', 'email' => $existing->email, 'role' => 'instructor']);
        }

        Notification::assertSentOnDemandTimes(TenantInvitationNotification::class, 1);
    }

    public function test_a_previously_wrong_question_needs_spacing_before_it_counts_as_mastered(): void
    {
        $calc = new MasteryCalculator;
        $progress = new Progress(['attempt_count' => 0, 'correct_count' => 0, 'incorrect_count' => 0, 'current_streak' => 0]);
        $now = Carbon::parse('2026-11-07 10:00');

        $calc->apply($progress, false, $now);
        foreach ([1, 2, 3] as $minute) {
            $calc->apply($progress, true, $now->copy()->addMinutes($minute));
        }
        $this->assertNotSame('gefestigt', $progress->learning_state, 'drei schnelle Wiederholungen direkt nach dem Fehler');

        $calc->apply($progress, true, $now->copy()->addMinutes(20));
        $this->assertSame('gefestigt', $progress->learning_state);
    }

    public function test_a_declined_extra_appointment_needs_action_even_while_the_exam_date_is_pending(): void
    {
        $submission = new PraxisFormSubmission;
        $submission->setRelation('examAppointments', collect([
            new PraxisExamAppointment(['slot' => 0, 'round' => 1, 'exam_date' => now()->addMonth(), 'label' => 'Prüfungstermin']),
            new PraxisExamAppointment(['slot' => 1, 'round' => 1, 'exam_date' => now()->addWeek(), 'label' => 'Navigationsstunde', 'response' => 'declined']),
        ]));

        $step = $submission->nextStep();

        $this->assertTrue($step['action_needed']);
        $this->assertStringContainsString('Navigationsstunde', $step['label']);
    }

    public function test_legal_page_names_cannot_become_school_slugs(): void
    {
        TenantPath::flushRouteSegments();
        $this->assertContains('impressum', TenantPath::reservedSegments());
        $this->assertContains('datenschutz', TenantPath::reservedSegments());
    }

    public function test_a_suspended_sole_owner_cannot_leave_the_school_without_an_owner(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner', ['password' => bcrypt('passwort-1234')]);
        $this->onAdmin(fn () => TenantUser::where('tenant_id', $tenant->id)->where('user_id', $owner->id)->update(['status' => 'suspended']));

        $this->actingAsInTenant($owner, $tenant)->delete('/profile', ['password' => 'passwort-1234'])->assertSessionHasErrors('owner', null, 'userDeletion');
        $this->onAdmin(fn () => $this->assertTrue(TenantUser::where('tenant_id', $tenant->id)->where('user_id', $owner->id)->exists()));
    }

    public function test_the_offboarding_export_leaves_out_platform_wide_sessions(): void
    {
        $tenant = $this->createTestTenant('Export Schule R9');
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $learner->id, 'ip_address' => '203.0.113.77', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]));

        $json = '';
        $this->onAdmin(fn () => app(TenantOffboardingService::class)->writeExport($tenant, function ($c) use (&$json) {
            $json .= $c;
        }));

        $this->assertStringNotContainsString('203.0.113.77', $json);
        $this->onAdmin(fn () => DB::table('sessions')->where('user_id', $learner->id)->delete());
    }
}
