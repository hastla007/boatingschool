<?php

namespace Tests\Feature\Api;

use App\Api\CourseAccess;
use App\Models\Attempt;
use App\Models\ContentQuestionRevision;
use App\Models\CourseAccessRule;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use App\Models\NavigationTask;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VideoModule;
use Illuminate\Support\Collection;

/**
 * QA-L-4/5/7: Lern-, Prüfungs-, Video-, Navigations-, Praxis- und Funk-
 * Endpunkte richten sich nach dem gemeldeten Funktionszugang (Capability,
 * Probezugang „erste N“) und der Drip-Sperre; die Auflösung eines Versuchs
 * bleibt gesperrt, solange die Frage in einer laufenden Prüfung steckt.
 */
class PartialAccessEndpointsApiTest extends MobileApiTestCase
{
    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_trial_trainer_serves_and_accepts_only_the_released_questions(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->rule($tenant, $course, 'question_bank', 'free_limited', 1);
        $token = $this->apiLogin($user);
        $modules = $this->moduleQuestionIds($course);
        $allowed = $modules->map(fn ($m) => $m['ids'][0])->unique();
        $locked = $modules->flatMap(fn ($m) => array_slice($m['ids'], 1))->diff($allowed)->first();

        for ($i = 0; $i < 4; $i++) {
            $question = $this->api('GET', "/courses/{$course->id}/trainer/next", $token, $tenant)->assertOk()->json('data.question');
            $this->assertContains($question['id'], $allowed->all());
        }
        $this->api('GET', "/courses/{$course->id}/trainer/next?mode=favorites&question_ids={$locked}", $token, $tenant)->assertNoContent();
        $this->api('GET', "/courses/{$course->id}/trainer/next?question_ids={$locked}", $token, $tenant)->assertNoContent();

        $this->api('POST', "/courses/{$course->id}/attempts", $token, $tenant, $this->attemptBody($user, $locked))
            ->assertStatus(403)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');
        $this->api('POST', "/courses/{$course->id}/attempts", $token, $tenant, $this->attemptBody($user, $allowed->first()))->assertCreated();
        $this->api('PUT', "/courses/{$course->id}/favorites/{$locked}", $token, $tenant)->assertStatus(403)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');
        $this->api('PUT', "/courses/{$course->id}/favorites/{$allowed->first()}", $token, $tenant)->assertOk();
    }

    public function test_drip_lock_applies_to_trainer_attempts_and_favorites_until_the_unlock_day(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $user, $course);
        $module = $this->onAdmin(fn () => $course->modules()->get()->last());
        $this->rule($tenant, $course, 'question_bank', 'drip', 7, (string) $module->code);
        $token = $this->apiLogin($user);
        $modules = $this->moduleQuestionIds($course);
        $open = $modules->where('code', '!=', (string) $module->code)->flatMap(fn ($m) => $m['ids']);
        $locked = collect($modules->firstWhere('code', (string) $module->code)['ids'])->diff($open)->first();
        $this->assertNotNull($locked, 'Testvoraussetzung: Frage nur im gesperrten Modul');

        $this->api('GET', "/courses/{$course->id}/trainer/next?module_id={$module->id}", $token, $tenant)
            ->assertStatus(403)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');
        $this->api('GET', "/courses/{$course->id}/trainer/next?question_ids={$locked}", $token, $tenant)->assertNoContent();
        $this->api('POST', "/courses/{$course->id}/attempts", $token, $tenant, $this->attemptBody($user, $locked))
            ->assertStatus(403)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');
        $this->api('PUT', "/courses/{$course->id}/favorites/{$locked}", $token, $tenant)->assertStatus(403);

        $this->travel(8)->days();
        $token = $this->apiLogin($user);
        $this->api('GET', "/courses/{$course->id}/trainer/next?module_id={$module->id}", $token, $tenant)->assertOk();
        $this->api('POST', "/courses/{$course->id}/attempts", $token, $tenant, $this->attemptBody($user, $locked))->assertCreated();
    }

    public function test_drip_rules_outside_their_validity_window_do_not_lock(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $user, $course);
        $module = $this->onAdmin(fn () => $course->modules()->get()->last());
        $this->rule($tenant, $course, 'question_bank', 'drip', 7, (string) $module->code, ['valid_until' => now()->subDay()]);
        $token = $this->apiLogin($user);

        $modules = collect($this->api('GET', "/courses/{$course->id}/modules", $token, $tenant)->assertOk()->json('data'));
        $this->assertFalse($modules->firstWhere('code', (string) $module->code)['locked']);
        $this->api('GET', "/courses/{$course->id}/trainer/next?module_id={$module->id}", $token, $tenant)->assertOk();
    }

    public function test_trial_limits_exams_videos_navigation_and_praxis_to_the_first_items(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        foreach (['exam_simulation', 'video_course', 'navigation_trainer', 'praxis_trainer'] as $feature) {
            $this->rule($tenant, $course, $feature, 'free_limited', 1);
        }
        $token = $this->apiLogin($user);

        $papers = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->orderBy('sort_order')->get());
        $options = $this->api('GET', "/courses/{$course->id}/exams", $token, $tenant)->assertOk()->json('data');
        $this->assertSame([$papers[0]->id], array_column($options, 'id'));
        $this->assertSame(1, $this->api('GET', "/courses/{$course->id}", $token, $tenant)->json('data.exam_options_count'));
        $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant, ['paper_id' => $papers[1]->id])
            ->assertStatus(403)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');

        $lessons = $this->onAdmin(fn () => VideoModule::where('course_id', $course->id)->orderBy('sort_order')->with('lessons')->get()->flatMap->lessons->values());
        $videos = $this->api('GET', "/courses/{$course->id}/videos", $token, $tenant)->assertOk();
        $this->assertSame(1, $videos->json('meta.lessons'));
        $this->api('GET', "/videos/{$lessons[0]->id}", $token, $tenant)->assertOk();
        $this->api('GET', "/videos/{$lessons[1]->id}", $token, $tenant)->assertStatus(403)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');

        $tasks = $this->api('GET', "/courses/{$course->id}/navigation-tasks", $token, $tenant)->assertOk()->json('data');
        $this->assertCount(1, $tasks);
        $other = $this->onAdmin(fn () => $course->navigationTasks()->where('id', '!=', $tasks[0]['id'])->value('id'));
        $this->api('GET', "/navigation-tasks/{$tasks[0]['id']}", $token, $tenant)->assertOk();
        $this->api('GET', "/navigation-tasks/{$other}?show_solution=1", $token, $tenant)->assertStatus(403)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');

        $praxis = $this->api('GET', "/courses/{$course->id}/praxis", $token, $tenant)->assertOk();
        $this->assertSame(1, $praxis->json('meta.total'));
        $card = $praxis->json('data.0.cards.0.id');
        $otherCard = $this->onAdmin(fn () => $course->praxisTasks()->where('id', '!=', $card)->value('id'));
        $this->api('PUT', "/courses/{$course->id}/praxis/{$card}", $token, $tenant, ['status' => 'known'])->assertOk();
        $this->api('PUT', "/courses/{$course->id}/praxis/{$otherCard}", $token, $tenant, ['status' => 'known'])->assertStatus(403);
    }

    public function test_feature_entitlements_open_their_endpoints_without_full_course_access(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $see = $this->existingCourse('SBF-SEE');
        $src = $this->existingCourse('SRC');
        $this->partialEntitlement($tenant, $user, $see, ['video', 'navigation', 'praxis']);
        $this->partialEntitlement($tenant, $user, $src, ['funk']);
        $token = $this->apiLogin($user);

        $this->api('GET', "/courses/{$see->id}/videos", $token, $tenant)->assertOk();
        $this->api('GET', "/courses/{$see->id}/navigation-tasks", $token, $tenant)->assertOk();
        $this->api('GET', "/courses/{$see->id}/praxis", $token, $tenant)->assertOk();
        $this->api('GET', "/courses/{$see->id}/exams", $token, $tenant)->assertStatus(403);
        $this->api('GET', "/courses/{$src->id}/radio", $token, $tenant)->assertOk();
        $this->api('GET', "/courses/{$src->id}/trainer/next", $token, $tenant)->assertStatus(403);

        // Funktraining nur mit vollem Umfang (keine „erste N“-Reihenfolge).
        $trial = $this->createTenantUser($tenant, 'learner');
        $this->rule($tenant, $src, 'radio_training', 'free_limited', 1);
        $this->api('GET', "/courses/{$src->id}/radio", $trial, $tenant)->assertStatus(403)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');
    }

    public function test_attempt_solution_is_locked_while_the_question_is_in_a_running_exam(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);
        $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->orderBy('paper_number')->firstOrFail());
        $moduleIds = $this->moduleQuestionIds($course)->flatMap(fn ($m) => $m['ids']);
        $questionId = $this->onAdmin(fn () => ExamPaperQuestion::where('exam_paper_id', $paper->id)->pluck('question_id'))
            ->first(fn ($id) => $moduleIds->contains($id));
        $this->assertNotNull($questionId, 'Testvoraussetzung: Bogenfrage auch im Fragenkatalog');

        $attemptId = $this->api('POST', "/courses/{$course->id}/attempts", $token, $tenant, $this->attemptBody($user, $questionId))
            ->assertCreated()->json('data.attempt_id');
        $this->api('GET', "/courses/{$course->id}/attempts/{$attemptId}", $token, $tenant)->assertOk();

        $exam = $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant, ['paper_id' => $paper->id])->assertCreated()->json('data');
        $this->api('GET', "/courses/{$course->id}/attempts/{$attemptId}", $token, $tenant)
            ->assertStatus(409)->assertJsonPath('code', 'EXAM_QUESTION_LOCKED');

        $this->api('POST', "/exams/{$exam['exam_id']}/submit", $token, $tenant, ['confirm_incomplete' => true]);
        $this->assertSame(1, $this->onAdmin(fn () => Attempt::where('id', $attemptId)->count()));
    }

    public function test_the_mirrored_copy_of_the_exam_navigation_task_is_locked_in_the_app(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $see = $this->existingCourse('SBF-SEE');
        $combo = $this->existingCourse('SBF-SEE-BIN');
        $this->grantEntitlement($tenant, $user, $see);
        $this->grantEntitlement($tenant, $user, $combo);
        $token = $this->apiLogin($user);
        $paperId = $this->onAdmin(fn () => ExamPaper::where('course_id', $see->id)->where('paper_number', 3)->value('id'));

        $exam = $this->api('POST', "/courses/{$see->id}/exams", $token, $tenant, ['paper_id' => $paperId])->assertCreated()->json('data');
        $copy = $this->onAdmin(fn () => NavigationTask::where('course_id', $combo->id)->where('source_task_id', $exam['navigation']['task_id'])->value('id'));
        $this->assertNotNull($copy, 'Testvoraussetzung: gespiegelte Aufgabe mit Quellverweis');

        $this->api('GET', "/navigation-tasks/{$copy}?show_solution=1", $token, $tenant)->assertStatus(409)->assertJsonPath('code', 'NAVIGATION_TASK_IN_EXAM');
        $list = collect($this->api('GET', "/courses/{$combo->id}/navigation-tasks", $token, $tenant)->assertOk()->json('data'));
        $this->assertTrue($list->firstWhere('id', $copy)['locked_by_exam']);
        $this->assertSame(1, $list->where('locked_by_exam', true)->count());
    }

    private function rule(Tenant $tenant, CourseDefinition $course, string $feature, string $type, ?int $limit = null, ?string $module = null, array $extra = []): CourseAccessRule
    {
        return $this->onAdmin(fn () => CourseAccessRule::create([
            'tenant_id' => $tenant->id, 'course_id' => $course->id, 'feature_code' => $feature,
            'access_type' => $type, 'limit_value' => $limit, 'module_code' => $module,
        ] + $extra));
    }

    private function partialEntitlement(Tenant $tenant, User $user, CourseDefinition $course, array $capabilities): Entitlement
    {
        return $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'course_id' => $course->id,
            'status' => 'active', 'source_type' => 'manual', 'valid_from' => now()->subMinute(),
            'capabilities' => $capabilities,
        ]));
    }

    /** @return Collection<int, array{id: string, code: string, ids: list<string>}> */
    private function moduleQuestionIds(CourseDefinition $course): Collection
    {
        return collect($this->onAdmin(fn () => app(CourseAccess::class)->moduleQuestionIds($course)))->filter(fn ($m) => $m['ids'] !== [])->values();
    }

    /** Antwort (die richtige) auf die veröffentlichte Revision einer Frage. */
    private function attemptBody(User $user, string $questionId): array
    {
        $revision = $this->onAdmin(fn () => ContentQuestionRevision::with('answers')->where('question_id', $questionId)
            ->where('editorial_status', 'published')->orderByDesc('revision_no')->firstOrFail());
        $body = ['question_id' => $questionId, 'revision_id' => $revision->id];

        return $revision->question_type === 'open_text'
            ? $body + ['known' => true]
            : $body + ['selected_answer_ids' => [$this->answerToken($user, $revision->answers->firstWhere('is_correct', true)->id)]];
    }
}
