<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\CourseExamRegistration;
use App\Models\ExamPaper;
use App\Models\ExamSession;
use App\Models\InstructorCourse;
use App\Models\NavigationTask;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\Product;
use App\Models\Progress;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Services\AccountSetupLink;
use App\Services\MasteryCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Regressionstests zu Review-Runde 11. */
class ReviewRoundElevenFixesTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    /** Eingeladenes, nie aktiviertes Konto, angelegt vor 100 Tagen. */
    private function oldInvitedAccount(): array
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['status' => 'invited']);
        $this->onAdmin(function () use ($user, $tenant) {
            DB::table('app_user')->where('id', $user->id)->update(['created_at' => now()->subDays(100)]);
            DB::table('tenant_user')->where('user_id', $user->id)->where('tenant_id', $tenant->id)->update(['created_at' => now()->subDays(100)]);
        });

        return [$tenant, $user];
    }

    private function userExists(User $user): bool
    {
        return $this->onAdmin(fn () => DB::table('app_user')->where('id', $user->id)->exists());
    }

    public function test_an_old_unclaimed_account_is_still_pruned(): void
    {
        [, $user] = $this->oldInvitedAccount();

        Artisan::call('app:prune-personal-data');

        $this->assertFalse($this->userExists($user));
    }

    public function test_a_re_sent_invitation_link_protects_the_account_for_the_full_period(): void
    {
        [$tenant, $user] = $this->oldInvitedAccount();
        $this->travel(-20)->days();
        $this->onAdmin(fn () => app(AccountSetupLink::class)->url($user, $tenant));
        $this->travelBack();

        Artisan::call('app:prune-personal-data');
        $this->assertTrue($this->userExists($user), 'Ein vor 20 Tagen neu verschickter Link zählt als neue Frist.');

        // Review-Runde 12: auch an den Folgetagen (die Link-Zeile bleibt erhalten).
        $this->travel(1)->days();
        Artisan::call('app:prune-personal-data');
        $this->assertTrue($this->userExists($user));
    }

    public function test_a_recent_membership_at_another_school_protects_an_unclaimed_account(): void
    {
        [, $user] = $this->oldInvitedAccount();
        $other = $this->createTestTenant('Andere Schule');
        $this->onAdmin(fn () => DB::table('tenant_user')->insert([
            'tenant_id' => $other->id, 'user_id' => $user->id, 'role' => 'learner', 'status' => 'active',
        ]));

        Artisan::call('app:prune-personal-data');

        $this->assertTrue($this->userExists($user));
    }

    public function test_an_unclaimed_account_with_a_product_purchase_is_kept(): void
    {
        [$tenant, $user] = $this->oldInvitedAccount();
        $this->onAdmin(fn () => DB::table('product_purchase')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'product_id' => Product::query()->value('id'),
        ]));

        Artisan::call('app:prune-personal-data');

        $this->assertTrue($this->userExists($user));
    }

    public function test_a_mastered_question_does_not_fall_back_after_another_quick_correct_answer(): void
    {
        $progress = new Progress;
        $progress->fill(['attempt_count' => 0, 'correct_count' => 0, 'incorrect_count' => 0, 'current_streak' => 0, 'mastery_score' => 0, 'learning_state' => 'neu']);
        $calculator = new MasteryCalculator;
        $t = Carbon::parse('2026-05-01 10:00');

        $calculator->apply($progress, false, $t);
        $calculator->apply($progress, true, $t->copy()->addMinutes(1));
        $calculator->apply($progress, true, $t->copy()->addMinutes(2));
        $calculator->apply($progress, true, $t->copy()->addMinutes(16));
        $this->assertSame('gefestigt', $progress->learning_state);

        $calculator->apply($progress, true, $t->copy()->addMinutes(19));
        $this->assertSame('gefestigt', $progress->learning_state);
        $this->assertSame(4, $progress->current_streak);
    }

    public function test_the_third_quick_correct_answer_after_a_mistake_still_does_not_master(): void
    {
        $progress = new Progress;
        $progress->fill(['attempt_count' => 0, 'correct_count' => 0, 'incorrect_count' => 0, 'current_streak' => 0, 'mastery_score' => 0, 'learning_state' => 'neu']);
        $calculator = new MasteryCalculator;
        $t = Carbon::parse('2026-05-01 10:00');

        foreach ([false, true, true, true] as $i => $correct) {
            $calculator->apply($progress, $correct, $t->copy()->addMinutes($i));
        }

        $this->assertNotSame('gefestigt', $progress->learning_state);
    }

    public function test_the_hourly_expiry_job_waits_for_the_navigation_submit_grace(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);
        $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->where('paper_number', 2)->firstOrFail());
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}")->assertRedirect();
        $session = $this->asTenant($tenant, fn () => ExamSession::where('user_id', $learner->id)->firstOrFail());
        $sq = $this->asTenant($tenant, fn () => $session->questions()->with('revision.answers')->first());
        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
            'position' => $sq->position, 'answer_id' => $sq->revision->answers->first()->id,
        ]);

        $this->travelTo($session->started_at->copy()->addSeconds(3600 + 30));
        Artisan::call('app:expire-abandoned-exam-sessions');
        $this->assertSame('running', $this->onAdmin(fn () => ExamSession::find($session->id)->status));

        $this->travelTo($session->started_at->copy()->addSeconds(3600 + 300));
        Artisan::call('app:expire-abandoned-exam-sessions');
        $this->assertNotSame('running', $this->onAdmin(fn () => ExamSession::find($session->id)->status));
    }

    public function test_an_instructor_does_not_see_praxis_registrations_of_other_courses_on_the_participant_page(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $learner = $this->createTenantUser($tenant, 'learner');
        $src = $this->existingCourse('SRC');
        $see = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $src);
        $this->grantEntitlement($tenant, $learner, $see);
        $this->onAdmin(function () use ($tenant, $instructor, $learner, $src, $see) {
            InstructorCourse::create(['tenant_id' => $tenant->id, 'user_id' => $instructor->id, 'course_id' => $src->id]);
            PraxisFormSubmission::create([
                'tenant_id' => $tenant->id, 'course_id' => $see->id, 'user_id' => $learner->id,
                'praxis_form_template_id' => PraxisFormTemplate::query()->value('id'), 'data' => ['geburtsort' => 'GEHEIM-ORT-XYZ'],
            ]);
        });

        $this->actingAsInTenant($instructor, $tenant)->get("/admin/participants/{$learner->id}")->assertOk()->assertDontSee('GEHEIM-ORT-XYZ');
    }

    public function test_the_practice_task_stays_locked_during_the_navigation_submit_grace(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);
        $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->where('paper_number', 2)->firstOrFail());
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}")->assertRedirect();
        $session = $this->asTenant($tenant, fn () => ExamSession::where('user_id', $learner->id)->firstOrFail());

        $this->travelTo($session->started_at->copy()->addSeconds(3600 + 30));
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/exam/navigation/{$session->navigation_task_id}")
            ->assertRedirect(route('exam.show', $session));
    }

    public function test_a_school_deleting_a_participant_keeps_other_schools_open_invitations(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $other = $this->createTestTenant('Andere Schule');
        $this->onAdmin(fn () => TenantInvitation::create(['tenant_id' => $other->id, 'email' => $learner->email, 'role' => 'instructor']));

        $this->actingAsInTenant($admin, $tenant)->delete("/admin/participants/{$learner->id}/data", ['confirm' => 'LÖSCHEN'])->assertRedirect();

        $this->assertFalse($this->userExists($learner));
        $this->onAdmin(fn () => $this->assertTrue(TenantInvitation::withoutGlobalScopes()->where('tenant_id', $other->id)->where('email', $learner->email)->exists()));
    }

    public function test_an_invalid_practice_proposal_leaves_no_empty_container(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);
        $this->onAdmin(fn () => CourseExamRegistration::create(['tenant_id' => $tenant->id, 'course_id' => $course->id, 'mode' => 'self']));

        $this->actingAsInTenant($admin, $tenant)->post("/admin/participants/{$learner->id}/practice-appointments", [
            'course_id' => $course->id, 'label' => 'X', 'exam_date' => 'kein-datum',
        ])->assertSessionHasErrors('exam_date');

        $this->onAdmin(fn () => $this->assertFalse(PraxisFormSubmission::where('user_id', $learner->id)->exists()));
    }

    public function test_the_exam_navigation_part_hides_the_hint_and_uses_format_placeholders(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);
        $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->where('paper_number', 1)->firstOrFail());
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}")->assertRedirect();
        $session = $this->asTenant($tenant, fn () => ExamSession::where('user_id', $learner->id)->firstOrFail());
        foreach ($this->asTenant($tenant, fn () => $session->questions()->with('revision.answers')->get()) as $sq) {
            $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                'position' => $sq->position, 'answer_id' => $sq->revision->answers->firstWhere('is_correct', true)->id,
            ]);
        }
        $hint = $this->onAdmin(fn () => NavigationTask::find($session->navigation_task_id)->hint);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}")->assertOk()->assertSee('Format: 000°');
        if ($hint) {
            $response->assertDontSee($hint);
        }
        $response->assertDontSee('z. B. 079°');
    }

    public function test_the_dashboard_mentions_an_expired_course_next_to_active_ones(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $this->existingCourse('SRC'));
        $expired = $this->grantEntitlement($tenant, $learner, $see = $this->existingCourse('SBF-SEE'));
        $this->onAdmin(fn () => DB::table('entitlement')->where('id', $expired->id)->update(['valid_from' => now()->subDays(30), 'valid_until' => now()->subDay()]));

        $this->actingAsInTenant($learner, $tenant)->get('/dashboard')->assertOk()->assertSee('Abgelaufen: '.$see->name, false);
    }

    public function test_array_input_on_email_and_password_fields_is_a_validation_error_not_a_server_error(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/register', ['name' => 'x', 'email' => ['a@b.de'], 'password' => 'geheim-123456', 'password_confirmation' => 'geheim-123456'])
            ->assertSessionHasErrors('email');
        $this->actingAsInTenant($owner, $tenant)->post('/admin/participants/invite', ['name' => 'x', 'email' => ['a@b.de']])->assertSessionHasErrors('email');
        $this->actingAsInTenant($owner, $tenant)->put('/password', ['current_password' => ['x'], 'password' => 'neu-12345678', 'password_confirmation' => 'neu-12345678'])->assertStatus(302);
        $this->actingAsInTenant($owner, $tenant)->post('/confirm-password', ['password' => ['x']])->assertSessionHasErrors('password');
    }

    public function test_impossible_audit_dates_are_ignored_and_null_bytes_are_stripped(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $this->actingAsInTenant($owner, $tenant)->get('/admin/audit?to=2026-13-45&from=2026-02-30')->assertOk();
        $superadmin = $this->createSuperAdmin();
        $this->actingAs($superadmin)->get('/superadmin/audit?from=2026-02-30')->assertOk();

        $this->actingAsInTenant($owner, $tenant)->post('/admin/tenant-course-locations', [
            'praxis_ort' => "Hafen\0Nord", 'course_ids' => [],
        ])->assertRedirect();
        $this->asTenant($tenant, fn () => $this->assertDatabaseHas('tenant_course_location', ['praxis_ort' => 'HafenNord']));
    }

    public function test_editing_a_navigation_task_does_not_change_a_running_exam(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);
        $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->where('paper_number', 3)->firstOrFail());
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}")->assertRedirect();
        $session = $this->asTenant($tenant, fn () => ExamSession::where('user_id', $learner->id)->firstOrFail());
        foreach ($this->asTenant($tenant, fn () => $session->questions()->with('revision.answers')->get()) as $sq) {
            $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                'position' => $sq->position, 'answer_id' => $sq->revision->answers->firstWhere('is_correct', true)->id,
            ]);
        }
        $task = $this->onAdmin(fn () => NavigationTask::find($session->navigation_task_id));
        $original = $task->scenario_text;
        $this->onAdmin(fn () => $task->update(['scenario_text' => 'GEAENDERT-R11']));

        try {
            $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}")->assertOk()
                ->assertDontSee('GEAENDERT-R11')->assertSee(Str::limit($original, 40, ''));
        } finally {
            $this->onAdmin(fn () => $task->update(['scenario_text' => $original]));
        }
    }

    public function test_a_course_scoped_instructor_cannot_invite_people_they_would_not_see(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $this->onAdmin(fn () => InstructorCourse::create(['tenant_id' => $tenant->id, 'user_id' => $instructor->id, 'course_id' => $this->existingCourse('SRC')->id]));

        $this->actingAsInTenant($instructor, $tenant)->post('/admin/participants/invite', ['name' => 'Ivo', 'email' => 'ivo-'.uniqid().'@example.test'])
            ->assertSessionHasErrors('email');
    }

    public function test_bulk_renewal_does_not_offer_suspended_memberships(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $active = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Aktiv Anna']);
        $suspended = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Gesperrt Tim']);
        $course = $this->existingCourse('SRC');
        foreach ([$active, $suspended] as $learner) {
            $e = $this->grantEntitlement($tenant, $learner, $course);
            $this->onAdmin(fn () => DB::table('entitlement')->where('id', $e->id)->update(['valid_until' => now()->addDays(10)]));
        }
        $this->onAdmin(fn () => DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $suspended->id)->update(['status' => 'suspended']));

        $this->actingAsInTenant($owner, $tenant)->get('/admin/renewals?course_id='.$course->id)->assertOk()
            ->assertSee('Aktiv Anna')->assertDontSee('Gesperrt Tim');
    }

    public function test_saving_the_course_selection_unchanged_writes_no_audit_entries(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $enabled = $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('site_enabled', true)->pluck('id')->all());

        $this->actingAsInTenant($owner, $tenant)->patch('/admin/courses', ['enabled' => $enabled])->assertRedirect();
        $count = fn () => $this->onAdmin(fn () => DB::table('audit_log')->where('tenant_id', $tenant->id)->whereIn('action', ['course-selection.update', 'webshop-links.update', 'exam-registration.update'])->count());
        $first = $count();
        $this->actingAsInTenant($owner, $tenant)->patch('/admin/courses', ['enabled' => $enabled])->assertRedirect();

        $this->assertSame($first, $count());
    }
}
