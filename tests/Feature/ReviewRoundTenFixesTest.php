<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use App\Models\CourseExamRegistration;
use App\Models\ExamPaper;
use App\Models\ExamSession;
use App\Models\ExamSessionNavigationAnswer;
use App\Models\InstructorCourse;
use App\Models\NavigationQuestion;
use App\Models\PraxisFormSubmission;
use App\Models\Tenant;
use App\Models\TenantCourseLocation;
use App\Models\User;
use App\Services\AccountSetupLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Regressionstests zu Review-Runde 10 (Navigation als Prüfungsteil, Praxisstunden, Sicherheit). */
class ReviewRoundTenFixesTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    /** Bogen starten und alle Fragen richtig beantworten; die Navigation bleibt offen. */
    private function startSeePaper(int $number): array
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);
        $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->where('paper_number', $number)->firstOrFail());
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}")->assertRedirect();
        $session = $this->asTenant($tenant, fn () => ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail());
        foreach ($this->asTenant($tenant, fn () => $session->questions()->with('revision.answers')->get()) as $sq) {
            $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                'position' => $sq->position, 'answer_id' => $sq->revision->answers->firstWhere('is_correct', true)->id,
            ]);
        }

        return [$tenant, $learner, $course, $session];
    }

    private function navAnswers(Tenant $tenant, ExamSession $session)
    {
        return $this->asTenant($tenant, fn () => ExamSessionNavigationAnswer::where('exam_session_id', $session->id)->orderBy('position')->get());
    }

    public function test_chart_drawing_questions_left_empty_can_be_self_assessed_so_every_task_is_passable(): void
    {
        [$tenant, $learner, , $session] = $this->startSeePaper(11);
        $answers = $this->navAnswers($tenant, $session);
        $this->assertGreaterThanOrEqual(3, $answers->filter->isChartDrawing()->count(), 'Aufgabe 11 hat drei Karten-Eintragungen');

        $given = $answers->mapWithKeys(fn ($a) => [$a->id => $a->isChartDrawing() ? '' : $a->modelAnswer()])->all();
        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/navigation", ['answers' => $given])->assertRedirect();
        $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}")->assertOk()->assertSee('in die Karte eingetragen');
        $known = $answers->reject->isAutoCheckable()->mapWithKeys(fn ($a) => [$a->id => '1'])->all();
        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/navigation/assess", ['known' => $known])->assertRedirect();

        $fresh = $this->asTenant($tenant, fn () => ExamSession::find($session->id));
        $this->assertSame(9, $fresh->navigation_points);
        $this->assertTrue($fresh->passed);
    }

    public function test_a_navigation_submit_shortly_after_the_deadline_keeps_the_answers_and_assessment_time_does_not_count(): void
    {
        [$tenant, $learner, , $session] = $this->startSeePaper(2);
        $answers = $this->navAnswers($tenant, $session);
        $this->travelTo($session->started_at->copy()->addSeconds(3600 + 45));

        $given = $answers->mapWithKeys(fn ($a) => [$a->id => $a->modelAnswer()])->all();
        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/navigation", ['answers' => $given])->assertRedirect();
        $this->assertSame(9, $this->navAnswers($tenant, $session)->whereNotNull('answer')->count());

        // Selbsteinschätzung nach Ablauf: ein Neuladen wertet nicht vorzeitig aus.
        $this->travel(5)->minutes();
        $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}")->assertOk()->assertSee('Prüfung auswerten');
        $known = $answers->reject->isAutoCheckable()->mapWithKeys(fn ($a) => [$a->id => '1'])->all();
        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/navigation/assess", ['known' => $known]);

        $fresh = $this->asTenant($tenant, fn () => ExamSession::find($session->id));
        $this->assertSame('evaluated', $fresh->status);
        $this->assertSame(9, $fresh->navigation_points);
    }

    public function test_an_auto_check_false_negative_can_be_confirmed_as_equivalent(): void
    {
        [$tenant, $learner, , $session] = $this->startSeePaper(4);
        $answers = $this->navAnswers($tenant, $session);
        $auto = $answers->first->isAutoCheckable();
        $this->assertNotNull($auto);

        $given = $answers->mapWithKeys(fn ($a) => [$a->id => $a->id === $auto->id ? 'gleichwertig formuliert' : $a->modelAnswer()])->all();
        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/navigation", ['answers' => $given]);
        $known = $answers->reject->isAutoCheckable()->mapWithKeys(fn ($a) => [$a->id => '1'])->all() + [$auto->id => '1'];
        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/navigation/assess", ['known' => $known]);

        $this->assertSame(9, $this->asTenant($tenant, fn () => ExamSession::find($session->id))->navigation_points);
        $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}/result")->assertSee('(selbst bewertet)');
    }

    public function test_editing_the_task_does_not_change_a_running_exam_and_the_practice_page_is_locked_meanwhile(): void
    {
        [$tenant, $learner, $course, $session] = $this->startSeePaper(5);
        $first = $this->navAnswers($tenant, $session)->first();
        $taskId = $this->asTenant($tenant, fn () => ExamSession::find($session->id)->navigation_task_id);

        // Übung derselben Aufgabe ist während der Prüfung gesperrt.
        $this->actingAsInTenant($learner, $tenant)->get(route('exam.navigation.show', ['course' => $course, 'task' => $taskId]))->assertRedirect(route('exam.show', $session));

        // Superadmin löscht eine Teilfrage: die Antwort mit ihrer Musterlösung bleibt.
        $question = $this->onAdmin(fn () => NavigationQuestion::find($first->navigation_question_id));
        $snapshot = $question->only(['navigation_task_id', 'question_number', 'question_text', 'answer_text', 'sort_order']);
        $this->onAdmin(fn () => $question->delete());
        try {
            $this->assertSame(9, $this->navAnswers($tenant, $session)->count());
            $this->assertSame($snapshot['answer_text'], $this->navAnswers($tenant, $session)->first()->modelAnswer());
        } finally {
            $this->onAdmin(fn () => DB::table('navigation_question')->insert(['id' => $question->id] + $snapshot + ['created_at' => now()]));
        }
    }

    public function test_switching_back_to_school_registration_keeps_practice_lessons_visible(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);
        $this->onAdmin(fn () => CourseExamRegistration::create(['tenant_id' => $tenant->id, 'course_id' => $course->id, 'mode' => 'self']));

        // Fehlgeschlagener Vorschlag hinterlässt keinen leeren Behälter.
        $this->actingAsInTenant($admin, $tenant)->post("/admin/participants/{$learner->id}/practice-appointments", [
            'course_id' => $course->id, 'label' => 'Navistunde', 'exam_date' => now()->subDay()->format('Y-m-d H:i'),
        ])->assertSessionHasErrors('exam_date');
        $this->onAdmin(fn () => $this->assertFalse(PraxisFormSubmission::where('user_id', $learner->id)->exists()));

        $this->actingAsInTenant($admin, $tenant)->post("/admin/participants/{$learner->id}/practice-appointments", [
            'course_id' => $course->id, 'label' => 'Navistunde', 'exam_date' => now()->addWeek()->format('Y-m-d H:i'),
        ])->assertSessionHasNoErrors();

        $this->onAdmin(fn () => CourseExamRegistration::where('tenant_id', $tenant->id)->update(['mode' => 'school']));
        $this->actingAsInTenant($learner, $tenant)->get(route('praxis-pruefung.index', $course))->assertOk()->assertSee('Navistunde')->assertSee('Termine deiner Bootsschule');
    }

    public function test_an_instructor_cannot_propose_practice_lessons_outside_their_courses(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $learner = $this->createTenantUser($tenant, 'learner');
        $src = $this->existingCourse('SRC');
        $see = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $src);
        $this->grantEntitlement($tenant, $learner, $see);
        $this->onAdmin(function () use ($tenant, $instructor, $src, $see) {
            InstructorCourse::create(['tenant_id' => $tenant->id, 'user_id' => $instructor->id, 'course_id' => $src->id]);
            CourseExamRegistration::create(['tenant_id' => $tenant->id, 'course_id' => $see->id, 'mode' => 'self']);
        });

        $this->actingAsInTenant($instructor, $tenant)->post("/admin/participants/{$learner->id}/practice-appointments", [
            'course_id' => $see->id, 'label' => 'X', 'exam_date' => now()->addWeek()->format('Y-m-d H:i'),
        ])->assertNotFound();
        $this->onAdmin(fn () => $this->assertFalse(PraxisFormSubmission::where('user_id', $learner->id)->where('course_id', $see->id)->exists()));
    }

    public function test_an_invite_link_stops_working_after_a_normal_password_reset(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['status' => 'invited']);
        $inviteUrl = $this->onAdmin(fn () => app(AccountSetupLink::class)->url($user, $tenant));
        $resetToken = $this->onAdmin(fn () => Password::broker()->createToken($user));

        $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/reset-password', [
            'token' => $resetToken, 'email' => $user->email, 'password' => 'eigenes-passwort-1', 'password_confirmation' => 'eigenes-passwort-1',
        ])->assertSessionHasNoErrors();

        $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/reset-password', [
            'invite' => '1', 'token' => basename(parse_url($inviteUrl, PHP_URL_PATH)), 'email' => $user->email,
            'password' => 'fremdes-passwort-1', 'password_confirmation' => 'fremdes-passwort-1',
        ])->assertSessionHasErrors('email');
        $this->onAdmin(fn () => $this->assertTrue(Hash::check('eigenes-passwort-1', User::find($user->id)->password)));
    }

    public function test_a_failed_new_location_does_not_leak_into_the_existing_locations(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $this->actingAsInTenant($owner, $tenant)->post('/admin/tenant-course-locations', ['_form' => 'create', 'praxis_ort' => 'Hafen Ost', 'praxis_lat' => '54,33', 'praxis_lng' => '10,15'])
            ->assertSessionHasNoErrors();
        $this->onAdmin(fn () => $this->assertSame(54.33, (float) TenantCourseLocation::where('tenant_id', $tenant->id)->value('praxis_lat')));

        $this->actingAsInTenant($owner, $tenant)->from('/admin?tab=orte')->post('/admin/tenant-course-locations', ['_form' => 'create', 'praxis_ort' => 'QA Fehlversuch', 'praxis_lat' => 'abc']);
        $page = $this->actingAsInTenant($owner, $tenant)->withSession(['_old_input' => ['_form' => 'create', 'praxis_ort' => 'QA Fehlversuch']])->get('/admin?tab=orte')->assertOk();
        // Nur das Formular „Neuer Ort“ zeigt die abgelehnte Eingabe, nicht der bestehende Ort.
        $this->assertSame(1, substr_count($page->getContent(), 'value="QA Fehlversuch"'));
        $page->assertSee('value="Hafen Ost"', false);
    }

    public function test_a_question_edit_conflict_needs_an_explicit_overwrite(): void
    {
        $superadmin = $this->createSuperAdmin();
        $question = $this->onAdmin(fn () => ContentQuestion::whereHas('revisions', fn ($q) => $q->where('editorial_status', 'published')->where('question_type', 'single_choice'))->firstOrFail());
        $published = $this->onAdmin(fn () => $question->publishedRevision()->load('answers'));

        $this->actingAs($superadmin)->patch("/superadmin/questions/{$question->id}", [
            'question_type' => 'single_choice',
            'question_text' => $published->question_text.' (Konflikt)',
            'answers' => $published->answers->mapWithKeys(fn ($a) => [$a->answer_key => $a->answer_text])->all(),
            'correct' => $published->answers->firstWhere('is_correct', true)->answer_key,
            'base_revision_no' => $published->revision_no - 1,
        ])->assertSessionHasErrors('question_text');
        $this->onAdmin(fn () => $this->assertSame($published->revision_no, $question->publishedRevision()->revision_no));

        $this->actingAs($superadmin)->withSession(['_old_input' => ['base_revision_no' => $published->revision_no - 1]])
            ->get("/superadmin/questions/{$question->id}/edit")->assertOk()->assertSee('name="overwrite_newer"', false);
    }

    public function test_members_of_a_suspended_school_can_still_log_in_to_get_their_data(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner', ['password' => bcrypt('passwort-1234')]);
        $this->onAdmin(fn () => $tenant->update(['status' => 'suspended']));

        $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/login', ['email' => $learner->email, 'password' => 'passwort-1234'])->assertRedirect();
        $this->assertAuthenticatedAs($learner);
        $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/meine-daten')->assertOk();
        $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/dashboard')->assertStatus(403)->assertSee('Eigene Daten herunterladen');
    }

    public function test_an_empty_navigation_answer_cannot_be_self_graded_as_correct(): void
    {
        [$tenant, $learner, , $session] = $this->startSeePaper(7);
        $answers = $this->navAnswers($tenant, $session);
        $empty = $answers->first(fn ($a) => ! $a->isChartDrawing() && ! $a->isAutoCheckable()) ?? $answers->first(fn ($a) => ! $a->isChartDrawing());

        $given = $answers->mapWithKeys(fn ($a) => [$a->id => $a->id === $empty->id ? '' : $a->modelAnswer()])->all();
        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/navigation", ['answers' => $given]);
        $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}")->assertSee('Keine Antwort');
        $known = $answers->mapWithKeys(fn ($a) => [$a->id => '1'])->all();
        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/navigation/assess", ['known' => $known]);

        $this->assertSame(8, $this->asTenant($tenant, fn () => ExamSession::find($session->id))->navigation_points);
    }
}
