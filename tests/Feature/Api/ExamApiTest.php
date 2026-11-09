<?php

namespace Tests\Feature\Api;

use App\Models\ContentAnswer;
use App\Models\ExamPaper;
use App\Models\ExamSession;
use Illuminate\Support\Facades\DB;

/**
 * Mobile-API: Prüfungssimulation mit derselben Engine wie im Web --
 * keine Lösung vor der Abgabe, Antworten festgeschrieben, Zeitlimit
 * serverseitig, Navigationsaufgabe als eigener Teil.
 */
class ExamApiTest extends MobileApiTestCase
{
    public function test_full_exam_flow_without_solution_leak_and_locked_answers(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);

        $options = $this->api('GET', "/courses/{$course->id}/exams", $token, $tenant)->assertOk();
        $option = $options->json('data.0');
        $this->assertSame('fixed_paper', $option['kind']);
        $this->assertTrue($option['startable']);
        $this->assertSame(1800, $option['duration_seconds']);
        $this->assertNull($option['running_exam_id']);

        // Ohne Bogen: Validierungsfehler; mit Bogen: Start (201), erneut: Fortsetzen (200).
        $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant)->assertStatus(422);
        $started = $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant, ['paper_id' => $option['id']])->assertCreated();
        $examId = $started->json('data.exam_id');
        $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant, ['paper_id' => $option['id']])->assertOk()->assertJsonPath('data.exam_id', $examId);
        $this->assertSame($examId, $this->api('GET', "/courses/{$course->id}/exams", $token, $tenant)->json('data.0.running_exam_id'));

        $exam = $this->api('GET', "/exams/{$examId}", $token, $tenant)->assertOk()
            ->assertJsonPath('data.status', 'running')->assertJsonPath('data.phase', 'questions')->assertJsonPath('data.answered_count', 0);
        $this->assertNotNull($exam->json('data.expires_at'));
        $this->assertGreaterThan(0, $exam->json('data.remaining_seconds'));
        // Keine Lösung, keine Erklärung, kein Richtig/Falsch in der laufenden Prüfung.
        foreach (['is_correct', 'correct_answer_ids', 'explanation', 'feedback'] as $leak) {
            $this->assertStringNotContainsString('"'.$leak, $exam->getContent());
        }

        $q1 = $exam->json('data.questions.0');
        $answers = $this->onAdmin(fn () => ContentAnswer::where('revision_id', $q1['revision_id'])->get());
        $right = $answers->firstWhere('is_correct', true);
        $wrong = $answers->firstWhere('is_correct', false);

        $saved = $this->api('PUT', "/exams/{$examId}/answers/{$q1['id']}", $token, $tenant, ['selected_answer_ids' => [$right->id]])->assertOk()
            ->assertJsonPath('data.saved', true)->assertJsonPath('data.answered_count', 1);
        $this->assertStringNotContainsString('"correct"', $saved->getContent());
        // Dieselbe Antwort noch einmal (Netzabbruch): harmlos. Eine andere: festgeschrieben.
        $this->api('PUT', "/exams/{$examId}/answers/{$q1['id']}", $token, $tenant, ['selected_answer_ids' => [$right->id]])->assertOk();
        $this->api('PUT', "/exams/{$examId}/answers/{$q1['id']}", $token, $tenant, ['selected_answer_ids' => [$wrong->id]])
            ->assertStatus(409)->assertJsonPath('code', 'EXAM_ANSWER_LOCKED');
        // Antwort einer anderen Frage: unbekannt.
        $q2 = $exam->json('data.questions.1');
        $this->api('PUT', "/exams/{$examId}/answers/{$q2['id']}", $token, $tenant, ['selected_answer_ids' => [$right->id]])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->api('PUT', "/exams/{$examId}/answers/{$q2['id']}", $token, $tenant, ['selected_answer_ids' => []])->assertStatus(422);

        // Ergebnis erst nach der Abgabe; fremde Nutzer sehen die Prüfung nicht.
        $this->api('GET', "/exams/{$examId}/result", $token, $tenant)->assertStatus(409)->assertJsonPath('code', 'EXAM_NOT_SUBMITTED');
        $other = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $other, $course);
        $this->api('GET', "/exams/{$examId}", $other, $tenant)->assertNotFound();

        $result = $this->api('POST', "/exams/{$examId}/submit", $token, $tenant)->assertOk()
            ->assertJsonPath('data.status', 'evaluated')->assertJsonPath('data.correct_count', 1)->assertJsonPath('data.passed', false);
        $this->assertSame([$right->id], $result->json('data.questions.0.solution.correct_answer_ids'));
        $this->assertTrue($result->json('data.questions.0.correct'));
        $this->assertNotEmpty($result->json('data.by_topic'));
        $this->assertSame($result->json('data.question_count') - 1, $result->json('data.unanswered_count'));

        // Abgegeben ist abgegeben.
        $this->api('POST', "/exams/{$examId}/submit", $token, $tenant)->assertOk()->assertJsonPath('data.correct_count', 1);
        $this->api('GET', "/exams/{$examId}/result", $token, $tenant)->assertOk()->assertJsonPath('data.exam_id', $examId);
        $this->api('PUT', "/exams/{$examId}/answers/{$q2['id']}", $token, $tenant, ['selected_answer_ids' => [$right->id]])
            ->assertStatus(409)->assertJsonPath('code', 'EXAM_ALREADY_SUBMITTED');
        $this->assertSame($examId, $this->api('GET', "/courses/{$course->id}/exams", $token, $tenant)->json('data.0.last_result.exam_id'));
    }

    public function test_answers_after_the_time_limit_do_not_count(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);
        $paperId = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->orderBy('paper_number')->value('id'));

        $exam = $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant, ['paper_id' => $paperId])->assertCreated()->json('data');
        $this->onAdmin(fn () => DB::table('exam_session')->where('id', $exam['exam_id'])->update(['started_at' => now()->subMinutes(31)]));

        $q1 = $exam['questions'][0];
        $answer = $this->onAdmin(fn () => ContentAnswer::where('revision_id', $q1['revision_id'])->value('id'));
        $this->api('PUT', "/exams/{$exam['exam_id']}/answers/{$q1['id']}", $token, $tenant, ['selected_answer_ids' => [$answer]])
            ->assertStatus(410)->assertJsonPath('code', 'EXAM_EXPIRED');
        $this->api('GET', "/exams/{$exam['exam_id']}", $token, $tenant)->assertOk()->assertJsonPath('data.status', 'evaluated');
        $this->api('GET', "/exams/{$exam['exam_id']}/result", $token, $tenant)->assertOk()->assertJsonPath('data.correct_count', 0);
    }

    public function test_exam_needs_an_active_entitlement(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $entitlement = $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);
        $paperId = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->orderBy('paper_number')->value('id'));
        $examId = $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant, ['paper_id' => $paperId])->json('data.exam_id');

        $this->onAdmin(fn () => DB::table('entitlement')->where('id', $entitlement->id)->update(['status' => 'revoked']));
        $this->api('GET', "/exams/{$examId}", $token, $tenant)->assertStatus(403)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');
    }

    public function test_sbf_see_navigation_part_is_submitted_assessed_and_counted(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);
        $paperId = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->where('paper_number', 3)->value('id'));

        $exam = $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant, ['paper_id' => $paperId])->assertCreated()->json('data');
        $this->assertSame(['min_points' => 7, 'of' => 9], $exam['navigation']['rule']);
        $this->assertNull($exam['navigation']['parts'][0]['solution'], 'Musterlösung erst bei der Selbsteinschätzung');

        // Die Navigationsaufgabe ist im Übungsbereich so lange gesperrt.
        $taskId = $exam['navigation']['task_id'];
        $this->api('GET', "/navigation-tasks/{$taskId}?show_solution=1", $token, $tenant)->assertStatus(409)->assertJsonPath('code', 'NAVIGATION_TASK_IN_EXAM');

        $model = $this->onAdmin(fn () => ExamSession::findOrFail($exam['exam_id'])->navigationAnswers()->with('question')->get());
        $given = $model->mapWithKeys(fn ($a) => [$a->id => $a->modelAnswer()])->all();
        // Erst der Fragebogen, dann die Navigation (wie im Web).
        $this->api('PUT', "/exams/{$exam['exam_id']}/navigation", $token, $tenant, ['answers' => $given])
            ->assertStatus(409)->assertJsonPath('code', 'EXAM_QUESTIONS_OPEN');
        // Abgeben ohne Navigation nur ausdrücklich.
        $this->api('POST', "/exams/{$exam['exam_id']}/submit", $token, $tenant)->assertStatus(409)->assertJsonPath('code', 'EXAM_NAVIGATION_NOT_SUBMITTED');

        // Zeit um, Fragen offen: in der Nachfrist zählt nur noch die Navigation.
        $this->onAdmin(fn () => DB::table('exam_session')->where('id', $exam['exam_id'])->update(['started_at' => now()->subSeconds(3600 + 30)]));
        $this->api('GET', "/exams/{$exam['exam_id']}", $token, $tenant)->assertOk()
            ->assertJsonPath('data.status', 'running')->assertJsonPath('data.phase', 'navigation');
        $q1 = $exam['questions'][0];
        $this->api('PUT', "/exams/{$exam['exam_id']}/answers/{$q1['id']}", $token, $tenant, ['selected_answer_ids' => [$q1['answers'][0]['id']]])
            ->assertStatus(410)->assertJsonPath('code', 'EXAM_EXPIRED');
        $this->api('GET', "/exams/{$exam['exam_id']}", $token, $tenant)->assertJsonPath('data.status', 'running');

        $this->api('PUT', "/exams/{$exam['exam_id']}/navigation", $token, $tenant, ['answers' => ['00000000-0000-0000-0000-000000000000' => 'x']])->assertStatus(422);
        $submitted = $this->api('PUT', "/exams/{$exam['exam_id']}/navigation", $token, $tenant, ['answers' => $given])->assertOk()
            ->assertJsonPath('data.phase', 'navigation_assessment');
        $this->assertNotNull($submitted->json('data.navigation.parts.0.solution'));
        $this->api('PUT', "/exams/{$exam['exam_id']}/navigation", $token, $tenant, ['answers' => $given])
            ->assertStatus(409)->assertJsonPath('code', 'EXAM_NAVIGATION_ALREADY_SUBMITTED');

        $known = $model->mapWithKeys(fn ($a) => [$a->id => true])->all();
        $result = $this->api('POST', "/exams/{$exam['exam_id']}/navigation/assessment", $token, $tenant, ['known' => $known])->assertOk()
            ->assertJsonPath('data.status', 'evaluated')->assertJsonPath('data.navigation.points', 9)->assertJsonPath('data.navigation.passed', true);
        // Fragebogen nicht beantwortet: trotz voller Navigation nicht bestanden.
        $this->assertFalse($result->json('data.passed'));
        $this->api('GET', "/navigation-tasks/{$taskId}", $token, $tenant)->assertOk();
    }

    public function test_an_exam_of_one_school_is_not_reachable_in_another(): void
    {
        $a = $this->createTestTenant();
        $b = $this->createTestTenant('Zweite Schule');
        $user = $this->createTenantUser($a, 'learner');
        $this->onAdmin(fn () => DB::table('tenant_user')->insert(['tenant_id' => $b->id, 'user_id' => $user->id, 'role' => 'learner', 'status' => 'active']));
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($a, $user, $course);
        $this->grantEntitlement($b, $user, $course);
        $token = $this->apiLogin($user);
        $paperId = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->orderBy('paper_number')->value('id'));

        $examId = $this->api('POST', "/courses/{$course->id}/exams", $token, $a, ['paper_id' => $paperId])->assertCreated()->json('data.exam_id');
        $this->api('GET', "/exams/{$examId}", $token, $b)->assertNotFound();
        $this->api('POST', "/exams/{$examId}/submit", $token, $b)->assertNotFound();
        $this->assertNull($this->api('GET', "/courses/{$course->id}/exams", $token, $b)->json('data.0.running_exam_id'));
    }
}
