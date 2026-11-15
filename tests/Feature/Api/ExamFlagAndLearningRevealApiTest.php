<?php

namespace Tests\Feature\Api;

use App\Models\ContentAnswer;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamSession;
use Illuminate\Support\Facades\DB;

/**
 * Mobile-API (WP-Parität, Teil Prüfung und Lernen): Prüfungsfragen markieren,
 * Musterantwort im Lernmodus per answer_token aufdecken, Fehlerliste.
 */
class ExamFlagAndLearningRevealApiTest extends MobileApiTestCase
{
    public function test_exam_questions_can_be_flagged_by_id_or_position_without_affecting_the_result(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);
        $paperId = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->orderBy('paper_number')->value('id'));

        $exam = $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant, ['paper_id' => $paperId])->assertCreated()->json('data');
        $examId = $exam['exam_id'];
        $this->assertSame(0, $exam['flagged_count']);
        $this->assertFalse($exam['questions'][0]['flagged']);
        $q1 = $exam['questions'][0];
        $q2 = $exam['questions'][1];

        // Per Frage-ID und per Position (1-basiert, WP-kompatibel).
        $this->api('PUT', "/exams/{$examId}/answers/{$q1['id']}/flag", $token, $tenant, ['flagged' => true])->assertOk()
            ->assertJsonPath('data.question_id', $q1['id'])->assertJsonPath('data.position', 1)
            ->assertJsonPath('data.flagged', true)->assertJsonPath('data.flagged_count', 1);
        $this->api('PUT', "/exams/{$examId}/answers/2/flag", $token, $tenant, ['flagged' => true])->assertOk()
            ->assertJsonPath('data.question_id', $q2['id'])->assertJsonPath('data.flagged_count', 2);

        // Markierung bleibt am Server (Fortsetzen auf einem anderen Gerät).
        $shown = $this->api('GET', "/exams/{$examId}", $token, $tenant)->assertOk()->assertJsonPath('data.flagged_count', 2);
        $this->assertTrue($shown->json('data.questions.0.flagged'));
        $this->assertTrue($shown->json('data.questions.1.flagged'));
        $this->assertFalse($shown->json('data.questions.2.flagged'));

        // Auch eine beantwortete Frage lässt sich (ent)markieren; die Antwort bleibt.
        $right = $this->onAdmin(fn () => ContentAnswer::where('revision_id', $q1['revision_id'])->where('is_correct', true)->value('id'));
        $this->api('PUT', "/exams/{$examId}/answers/{$q1['id']}", $token, $tenant, ['selected_answer_ids' => [$right]])->assertOk()
            ->assertJsonPath('data.flagged', true);
        $this->api('PUT', "/exams/{$examId}/answers/{$q1['id']}/flag", $token, $tenant, ['flagged' => false])->assertOk()
            ->assertJsonPath('data.flagged', false)->assertJsonPath('data.flagged_count', 1);

        // Ungültige Eingaben und unbekannte Fragen.
        $this->api('PUT', "/exams/{$examId}/answers/{$q2['id']}/flag", $token, $tenant)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->api('PUT', "/exams/{$examId}/answers/999/flag", $token, $tenant, ['flagged' => true])->assertNotFound();
        $this->api('PUT', "/exams/{$examId}/answers/00000000-0000-0000-0000-000000000000/flag", $token, $tenant, ['flagged' => true])->assertNotFound();
        $this->api('PUT', "/exams/{$examId}/answers/abc/flag", $token, $tenant, ['flagged' => true])->assertNotFound();

        // Fremde Personen sehen die Prüfung nicht.
        $other = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $other, $course);
        $this->api('PUT', "/exams/{$examId}/answers/{$q2['id']}/flag", $other, $tenant, ['flagged' => false])->assertNotFound();
        $this->assertTrue($this->onAdmin(fn () => (bool) DB::table('exam_session_question')
            ->where('exam_session_id', $examId)->where('question_id', $q2['id'])->value('flagged')));

        // Die Markierung zählt nicht in die Auswertung; danach ist nichts mehr zu markieren.
        $this->api('POST', "/exams/{$examId}/submit", $token, $tenant)->assertOk()->assertJsonPath('data.correct_count', 1);
        $this->api('PUT', "/exams/{$examId}/answers/{$q2['id']}/flag", $token, $tenant, ['flagged' => false])->assertStatus(409);
    }

    public function test_flagging_after_the_time_limit_is_rejected(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);
        $paperId = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->orderBy('paper_number')->value('id'));
        $exam = $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant, ['paper_id' => $paperId])->assertCreated()->json('data');
        $this->onAdmin(fn () => DB::table('exam_session')->where('id', $exam['exam_id'])->update(['started_at' => now()->subMinutes(31)]));

        $this->api('PUT', "/exams/{$exam['exam_id']}/answers/1/flag", $token, $tenant, ['flagged' => true])
            ->assertStatus(410)->assertJsonPath('code', 'EXAM_EXPIRED');
    }

    public function test_exam_reveal_alias_accepts_the_position_of_an_open_question(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('FKN');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);
        $paperId = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->orderBy('paper_number')->value('id'));
        $exam = $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant, ['paper_id' => $paperId])->assertCreated()->json('data');

        $open = collect($exam['questions'])->firstWhere('answer_selection', 'self_assessment');
        $closed = collect($exam['questions'])->first(fn ($q) => $q['answer_selection'] !== 'self_assessment');
        $this->assertNotNull($open, 'Testvoraussetzung: FKN-Bogen mit offener Frage');
        $expected = $this->onAdmin(fn () => ContentQuestionRevision::with('answers')->findOrFail($open['revision_id'])->correctAnswer()?->answer_text);

        $this->api('POST', "/exams/{$exam['exam_id']}/answers/{$open['position']}/reveal", $token, $tenant)->assertOk()
            ->assertJsonPath('data.question_id', $open['id'])->assertJsonPath('data.model_answer', $expected);
        $this->api('GET', "/exams/{$exam['exam_id']}", $token, $tenant)->assertOk()
            ->assertJsonPath('data.questions.'.($open['position'] - 1).'.revealed', true);
        if ($closed) {
            $this->api('POST', "/exams/{$exam['exam_id']}/answers/{$closed['position']}/reveal", $token, $tenant)->assertStatus(422);
        }
    }

    public function test_trainer_reveal_returns_the_model_answer_of_an_open_question_for_the_delivered_token(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('FKN');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);
        $open = $this->openTextRevision($course);

        // Gezielt genau diese offene Frage holen (Favorit + question_ids).
        $this->api('PUT', "/courses/{$course->id}/favorites/{$open->question_id}", $token, $tenant)->assertOk();
        $next = $this->api('GET', "/courses/{$course->id}/trainer/next?mode=favorites&question_ids={$open->question_id}", $token, $tenant);
        $next->assertOk()->assertJsonPath('data.question.id', $open->question_id);
        $answerToken = $next->json('data.answer_token');
        $this->assertSame($next->json('data.question.revision_id'), $answerToken);

        $this->api('POST', "/courses/{$course->id}/trainer/reveal", $token, $tenant, ['answer_token' => $answerToken])->assertOk()
            ->assertJsonPath('data.question_id', $open->question_id)->assertJsonPath('data.revision_id', $answerToken)
            ->assertJsonPath('data.model_answer', $open->correctAnswer()?->answer_text);

        // Ohne bzw. mit ungültigem Token: Validierung; unbekannte Revision: 404.
        $this->api('POST', "/courses/{$course->id}/trainer/reveal", $token, $tenant)->assertStatus(422);
        $this->api('POST', "/courses/{$course->id}/trainer/reveal", $token, $tenant, ['answer_token' => 'x'])->assertStatus(422);
        $this->api('POST', "/courses/{$course->id}/trainer/reveal", $token, $tenant, ['answer_token' => '00000000-0000-0000-0000-000000000000'])->assertNotFound();
    }

    public function test_trainer_reveal_rejects_closed_questions_foreign_courses_and_missing_access(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $fkn = $this->existingCourse('FKN');
        $src = $this->existingCourse('SRC');
        $entitlement = $this->grantEntitlement($tenant, $user, $fkn);
        $this->grantEntitlement($tenant, $user, $src);
        $token = $this->apiLogin($user);
        $open = $this->openTextRevision($fkn);

        // Einfachauswahl: keine Musterantwort aufzudecken.
        $closedQuestionId = $this->onAdmin(fn () => $src->contentQuestionIds()->first(fn ($id) => ContentQuestionRevision::where('question_id', $id)
            ->where('editorial_status', 'published')->where('question_type', '!=', 'open_text')->exists()));
        $closed = $this->onAdmin(fn () => ContentQuestionRevision::where('question_id', $closedQuestionId)->where('editorial_status', 'published')->firstOrFail());
        $this->api('POST', "/courses/{$src->id}/trainer/reveal", $token, $tenant, ['answer_token' => $closed->id])
            ->assertStatus(422)->assertJsonValidationErrors('answer_token');

        // Frage eines anderen Kurses: nicht gefunden (auch wenn dort Zugang besteht).
        if (! $this->onAdmin(fn () => $src->contentQuestionIds()->contains($open->question_id))) {
            $this->api('POST', "/courses/{$src->id}/trainer/reveal", $token, $tenant, ['answer_token' => $open->id])->assertNotFound();
        }

        // Ohne gültigen Kurszugang: 403.
        $this->onAdmin(fn () => DB::table('entitlement')->where('id', $entitlement->id)->update(['status' => 'revoked']));
        $this->api('POST', "/courses/{$fkn->id}/trainer/reveal", $token, $tenant, ['answer_token' => $open->id])
            ->assertStatus(403)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');
    }

    public function test_trainer_reveal_is_locked_while_the_question_is_in_a_running_exam(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('FKN');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);
        $paperId = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->orderBy('paper_number')->value('id'));
        $exam = $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant, ['paper_id' => $paperId])->assertCreated()->json('data');
        $open = collect($exam['questions'])->firstWhere('answer_selection', 'self_assessment');
        $this->assertNotNull($open, 'Testvoraussetzung: FKN-Bogen mit offener Frage');

        $this->api('POST', "/courses/{$course->id}/trainer/reveal", $token, $tenant, ['answer_token' => $open['revision_id']])
            ->assertStatus(409)->assertJsonPath('code', 'EXAM_QUESTION_LOCKED');
        // Die Prüfungsfrage gilt dadurch nicht als aufgedeckt.
        $this->assertNull($this->onAdmin(fn () => ExamSession::findOrFail($exam['exam_id'])->questions()->where('question_id', $open['id'])->value('revealed_at')));
    }

    public function test_mistakes_list_last_wrong_questions_with_favorite_state_and_without_solution(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);

        $this->api('GET', "/courses/{$course->id}/mistakes", $token, $tenant)->assertOk()->assertExactJson(['data' => []]);

        $question = $this->api('GET', "/courses/{$course->id}/trainer/next", $token, $tenant)->assertOk()->json('data.question');
        $revision = $this->onAdmin(fn () => ContentQuestionRevision::with('answers')->findOrFail($question['revision_id']));
        $body = ['question_id' => $question['id'], 'revision_id' => $question['revision_id']];
        $wrong = $revision->answers->firstWhere('is_correct', false);
        $correct = $revision->answers->firstWhere('is_correct', true);

        $this->api('POST', "/courses/{$course->id}/attempts", $token, $tenant, $body + ['selected_answer_ids' => [$wrong->id]])->assertCreated();
        $this->api('PUT', "/courses/{$course->id}/favorites/{$question['id']}", $token, $tenant)->assertOk();

        $list = $this->api('GET', "/courses/{$course->id}/mistakes", $token, $tenant)->assertOk();
        $this->assertCount(1, $list->json('data'));
        $item = $list->json('data.0');
        $this->assertSame($question['id'], $item['question_id']);
        $this->assertTrue($item['wrong']);
        $this->assertTrue($item['favorite']);
        $this->assertCount(1, $item['collection_ids']);
        $this->assertSame($item['collection_ids'][0], $item['collection_id']);
        $this->assertNotEmpty($item['text']);
        $this->assertNotNull($item['last_seen_at']);
        foreach (['is_correct', 'correct_answer', 'explanation', '"answers"'] as $leak) {
            $this->assertStringNotContainsString($leak, $list->getContent());
        }

        // Andere Personen sehen die Liste nicht; ohne Kurszugang 403.
        $other = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $other, $course);
        $this->api('GET', "/courses/{$course->id}/mistakes", $other, $tenant)->assertOk()->assertExactJson(['data' => []]);
        $stranger = $this->createTenantUser($tenant, 'learner');
        $this->api('GET', "/courses/{$course->id}/mistakes", $stranger, $tenant)->assertStatus(403);

        // Richtig beantwortet: nicht mehr in der Fehlerliste.
        $this->api('POST', "/courses/{$course->id}/attempts", $token, $tenant, $body + ['selected_answer_ids' => [$correct->id]])->assertCreated();
        $this->api('GET', "/courses/{$course->id}/mistakes", $token, $tenant)->assertOk()->assertExactJson(['data' => []]);
    }

    private function openTextRevision(CourseDefinition $course): ContentQuestionRevision
    {
        return $this->onAdmin(function () use ($course) {
            $revision = ContentQuestionRevision::with('answers')
                ->whereIn('question_id', $course->modules()->with('questions')->get()->flatMap->questions->pluck('id'))
                ->where('editorial_status', 'published')->where('question_type', 'open_text')
                ->whereHas('question', fn ($q) => $q->currentlyValid())
                ->firstOrFail();
            $this->assertNotNull($revision->correctAnswer(), 'Testvoraussetzung: Musterantwort vorhanden');

            return $revision;
        });
    }
}
