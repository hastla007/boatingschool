<?php

namespace Tests\Feature\Api;

use App\Models\ApiDataExport;
use App\Models\ContentAnswer;
use App\Models\ExamPaper;
use App\Models\ExamSession;
use App\Models\Favorite;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\AccountErasureService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Review-Runde 13: Funde der fünf Prüfer zur Mobile-API und den gemeinsamen Abläufen. */
class ReviewRoundThirteenApiTest extends MobileApiTestCase
{
    private function startPaper(string $code, int $number, string $token, $tenant): array
    {
        $course = $this->existingCourse($code);
        $paperId = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->where('paper_number', $number)->value('id'));

        return $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant, ['paper_id' => $paperId])->assertCreated()->json('data');
    }

    public function test_answer_order_and_keys_do_not_reveal_the_solution(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $user, $this->existingCourse('SRC'));
        $token = $this->apiLogin($user);

        $exam = $this->startPaper('SRC', 1, $token, $tenant);
        $correctIds = $this->onAdmin(fn () => ContentAnswer::whereIn('revision_id', collect($exam['questions'])->pluck('revision_id'))->where('is_correct', true)->pluck('id'))->flip();
        $firstIsCorrect = collect($exam['questions'])->filter(fn ($q) => $correctIds->has($q['answers'][0]['id']))->count();

        // Quelldaten: richtige Antwort immer „A“ an erster Stelle -- die App bekommt sie gemischt.
        $this->assertLessThan(count($exam['questions']), $firstIsCorrect);
        $this->assertSame(['A', 'B', 'C', 'D'], array_column($exam['questions'][0]['answers'], 'key'));
        // Dieselbe Reihenfolge wie im Web (je Person stabil).
        $again = $this->api('GET', "/exams/{$exam['exam_id']}", $token, $tenant)->json('data.questions.0.answers');
        $this->assertSame(array_column($exam['questions'][0]['answers'], 'id'), array_column($again, 'id'));
    }

    public function test_exam_lock_holds_across_schools_and_during_the_navigation_assessment(): void
    {
        $a = $this->createTestTenant();
        $b = $this->createTestTenant('Zweite Schule');
        $user = $this->createTenantUser($a, 'learner');
        $this->onAdmin(fn () => DB::table('tenant_user')->insert(['tenant_id' => $b->id, 'user_id' => $user->id, 'role' => 'learner', 'status' => 'active']));
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($a, $user, $course);
        $this->grantEntitlement($b, $user, $course);
        $token = $this->apiLogin($user);

        $exam = $this->startPaper('SRC', 1, $token, $a);
        $q = $exam['questions'][0];
        // Dieselbe Frage bei der zweiten Schule im Lernmodus: gesperrt (sonst gäbe es dort die Lösung).
        $this->api('POST', "/courses/{$course->id}/attempts", $token, $b, ['question_id' => $q['id'], 'revision_id' => $q['revision_id'], 'selected_answer_ids' => [$q['answers'][0]['id']]])
            ->assertStatus(409)->assertJsonPath('code', 'EXAM_QUESTION_LOCKED');
    }

    public function test_learning_answer_during_navigation_assessment_does_not_evaluate_the_exam(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);

        $exam = $this->startPaper('SBF-SEE', 3, $token, $tenant);
        $this->onAdmin(fn () => DB::table('exam_session')->where('id', $exam['exam_id'])->update(['started_at' => now()->subSeconds(3600 + 30)]));
        $answers = $this->onAdmin(fn () => ExamSession::findOrFail($exam['exam_id'])->navigationAnswers()->get());
        $this->api('PUT', "/exams/{$exam['exam_id']}/navigation", $token, $tenant, ['answers' => $answers->mapWithKeys(fn ($a) => [$a->id => 'x'])->all()])->assertOk();

        $q = $exam['questions'][0];
        $this->api('POST', "/courses/{$course->id}/attempts", $token, $tenant, ['question_id' => $q['id'], 'revision_id' => $q['revision_id'], 'selected_answer_ids' => [$q['answers'][0]['id']]])
            ->assertStatus(409)->assertJsonPath('code', 'EXAM_QUESTION_LOCKED');
        $this->assertSame('running', $this->onAdmin(fn () => ExamSession::find($exam['exam_id'])->status));
        $this->api('POST', "/exams/{$exam['exam_id']}/navigation/assessment", $token, $tenant, ['known' => []])->assertOk();
    }

    public function test_device_sessions_end_on_two_factor_changes_and_on_suspension(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);
        $this->onAdmin(fn () => User::findOrFail($user->id)->endOtherSessions());
        $this->api('GET', '/me', $token)->assertStatus(401);

        $token = $this->apiLogin($user);
        $admin = $this->createSuperAdmin();
        $this->actingAs($admin)->patch(route('superadmin.users.update', $user), [
            'first_name' => 'Test', 'email' => $user->email, 'status' => 'suspended',
        ])->assertRedirect();
        $this->onAdmin(fn () => DB::table('app_user')->where('id', $user->id)->update(['status' => 'active']));
        $this->api('GET', '/me', $token)->assertStatus(401);
        $this->assertSame(0, $this->onAdmin(fn () => DB::table('api_device_session')->where('user_id', $user->id)->whereNull('revoked_at')->count()));
    }

    public function test_failed_requests_are_not_replayed_by_idempotency(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $token = $this->apiLogin($user);
        $paperId = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->orderBy('paper_number')->value('id'));
        $key = (string) Str::uuid();

        $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant, ['paper_id' => $paperId], ['Idempotency-Key' => $key])->assertStatus(403);
        $this->grantEntitlement($tenant, $user, $course);
        $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant, ['paper_id' => $paperId], ['Idempotency-Key' => $key])->assertCreated();
    }

    public function test_maintenance_app_config_and_minimum_version(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);

        $this->getJson('/api/v1/app/config')->assertOk()->assertJsonPath('data.maintenance.active', false);
        config(['mobile_api.minimum_version.ios' => '2.0.0']);
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token, 'X-App-Platform' => 'ios', 'X-App-Version' => '1.9.3'])
            ->assertStatus(426)->assertJsonPath('code', 'APP_UPDATE_REQUIRED')->assertJsonPath('minimum_version', '2.0.0');
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token, 'X-App-Platform' => 'ios', 'X-App-Version' => '2.1.0'])->assertOk();

        $settings = $this->onAdmin(fn () => PlatformSetting::query()->first());
        $before = $settings?->maintenance_mode;
        try {
            $this->onAdmin(fn () => DB::table('platform_setting')->update(['maintenance_mode' => true]));
            $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token])->assertStatus(503)->assertJsonPath('code', 'MAINTENANCE');
            $this->getJson('/api/v1/app/config')->assertOk()->assertJsonPath('data.maintenance.active', $settings !== null);
        } finally {
            $this->onAdmin(fn () => DB::table('platform_setting')->update(['maintenance_mode' => (bool) $before]));
        }
    }

    public function test_favorites_list_shows_question_and_rejects_empty_collections(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);
        $questionId = $this->api('GET', "/courses/{$course->id}/trainer/next", $token, $tenant)->json('data.question.id');

        // Altbestand ohne Sammlung: landet wie im Web in der Standard-Sammlung.
        $this->onAdmin(fn () => Favorite::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'question_id' => $questionId, 'context' => Favorite::CONTEXT_SMART_LEARNING]));
        $list = $this->api('GET', "/courses/{$course->id}/favorites", $token, $tenant)->assertOk();
        $this->assertNotEmpty($list->json('data.0.question.text'));
        $this->assertCount(1, $list->json('data.0.collection_ids'));
        $collectionId = $list->json('data.0.collection_ids.0');

        $this->api('PUT', "/courses/{$course->id}/favorites/{$questionId}", $token, $tenant, ['collection_ids' => []])->assertStatus(422);
        $this->api('PUT', "/courses/{$course->id}/favorites/{$questionId}", $token, $tenant, ['collection_ids' => [(string) Str::uuid()]])->assertStatus(422);
        $this->api('GET', "/courses/{$course->id}/favorites", $token, $tenant)->assertJsonCount(1, 'data');
        // Eine Sammlung üben.
        $this->api('GET', "/courses/{$course->id}/trainer/next?collection_id={$collectionId}", $token, $tenant)->assertOk()->assertJsonPath('data.question.id', $questionId);
    }

    public function test_leaving_a_school_deletes_export_files_immediately(): void
    {
        Storage::fake('local');
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $token = $this->apiLogin($user);
        $this->api('POST', '/profile/export', $token, $tenant)->assertStatus(202);
        $this->assertCount(1, Storage::disk('local')->files('api-exports'));

        $this->asTenant($tenant, function () use ($tenant, $user) {
            app(TenantContext::class)->set($tenant);
            app(AccountErasureService::class)->leaveTenant(User::findOrFail($user->id), $tenant);
        });
        $this->assertCount(0, Storage::disk('local')->files('api-exports'));
        $this->assertFalse($this->onAdmin(fn () => ApiDataExport::withoutGlobalScopes()->where('user_id', $user->id)->exists()));
    }

    public function test_api_is_not_served_under_a_school_path_and_other_devices_can_be_logged_out(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $current = $this->apiLogin($user);
        $other = $this->apiLogin($user);

        $this->getJson('/'.$tenant->slug.'/api/v1/me', ['Authorization' => 'Bearer '.$current])->assertNotFound();
        $this->api('DELETE', '/me/sessions', $current)->assertNoContent();
        $this->api('GET', '/me', $other)->assertStatus(401);
        $this->api('GET', '/me', $current)->assertOk();
    }

    public function test_exam_history_lists_evaluated_attempts(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);
        $exam = $this->startPaper('SRC', 1, $token, $tenant);
        $this->api('POST', "/exams/{$exam['exam_id']}/submit", $token, $tenant)->assertOk();

        $this->api('GET', "/courses/{$course->id}/exam-sessions", $token, $tenant)->assertOk()
            ->assertJsonPath('data.0.exam_id', $exam['exam_id'])->assertJsonPath('data.0.name', 'Bogen 1')
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_web_rejects_navigation_before_the_questions_and_late_self_assessment(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $user, $course);
        $exam = $this->startPaper('SBF-SEE', 3, $this->apiLogin($user), $tenant);
        $answers = $this->onAdmin(fn () => ExamSession::findOrFail($exam['exam_id'])->navigationAnswers()->get());
        $given = $answers->mapWithKeys(fn ($a) => [$a->id => $a->modelAnswer()])->all();

        $this->actingAsInTenant($user, $tenant)->post("/exam-sessions/{$exam['exam_id']}/navigation", ['answers' => $given])
            ->assertRedirect()->assertSessionHas('status');
        $this->assertNull($this->onAdmin(fn () => ExamSession::find($exam['exam_id'])->navigation_submitted_at));

        // Abgabe in der Nachfrist, Selbsteinschätzung erst nach dem Fenster: zählt nicht (wie in der App).
        $this->onAdmin(fn () => DB::table('exam_session')->where('id', $exam['exam_id'])->update(['started_at' => now()->subSeconds(3600 + 30)]));
        $this->actingAsInTenant($user, $tenant)->post("/exam-sessions/{$exam['exam_id']}/navigation", ['answers' => $given])->assertRedirect();
        $this->onAdmin(fn () => DB::table('exam_session')->where('id', $exam['exam_id'])->update(['started_at' => now()->subSeconds(3600 + 901)]));
        $this->actingAsInTenant($user, $tenant)->post("/exam-sessions/{$exam['exam_id']}/navigation/assess", ['known' => $answers->mapWithKeys(fn ($a) => [$a->id => '1'])->all()])
            ->assertRedirect(route('exam.result', $exam['exam_id']));
        $session = $this->onAdmin(fn () => ExamSession::find($exam['exam_id']));
        $this->assertSame('evaluated', $session->status);
        $this->assertNull($session->navigationAnswers()->whereNotNull('self_assessed_known')->where('self_assessed_known', true)->first());
    }
}
