<?php

namespace Tests\Feature\Api;

use App\Models\Attempt;
use App\Models\ContentAnswer;
use App\Models\ContentQuestionRevision;
use App\Models\ExamPaper;
use App\Models\ExamSession;
use App\Models\Favorite;
use App\Models\User;
use App\Support\AnswerToken;
use Illuminate\Support\Str;

/**
 * Sicherheitsfund SEC-A1: Antwort-IDs sind zeitlich sortierbar (UUIDv7), die
 * richtige Antwort der amtlichen Kataloge wurde zuerst angelegt -- die
 * kleinste ID war die Lösung. Web und App bekommen deshalb nur Kennungen je
 * Person (AnswerToken), nie die echten IDs, und nehmen auch nur diese an.
 */
class AnswerTokenTest extends MobileApiTestCase
{
    public function test_token_is_uuid_shaped_stable_per_person_and_differs_between_people(): void
    {
        $answerId = (string) Str::orderedUuid();
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();

        $token = AnswerToken::for($a, $answerId);
        $this->assertTrue(Str::isUuid($token));
        $this->assertSame('4', $token[14], 'Version-4-Bits gesetzt');
        $this->assertContains($token[19], ['8', '9', 'a', 'b'], 'Variante RFC 4122');
        $this->assertSame($token, AnswerToken::for($a, $answerId));
        $this->assertNotSame($token, AnswerToken::for($b, $answerId));
        $this->assertNotSame($answerId, $token);

        $other = (string) Str::orderedUuid();
        $this->assertSame($answerId, AnswerToken::resolve($a, $token, [$other, $answerId]));
        $this->assertSame($answerId, AnswerToken::resolve($a, strtoupper($token), [$other, $answerId]));
        $this->assertNull(AnswerToken::resolve($b, $token, [$other, $answerId]), 'fremde Kennung');
        $this->assertNull(AnswerToken::resolve($a, $answerId, [$other, $answerId]), 'echte ID ist keine Kennung');
        $this->assertSame([AnswerToken::UNKNOWN, $answerId], AnswerToken::resolveMany($a, [(string) Str::uuid(), $token], [$answerId]));
    }

    public function test_new_answers_get_random_v4_ids(): void
    {
        $this->assertSame('4', (new ContentAnswer)->newUniqueId()[14]);
    }

    public function test_api_trainer_and_exam_never_expose_real_answer_ids(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);

        // Trainer: Kennungen statt IDs.
        $next = $this->api('GET', "/courses/{$course->id}/trainer/next", $token, $tenant)->assertOk();
        $question = $next->json('data.question');
        $revision = $this->onAdmin(fn () => ContentQuestionRevision::with('answers')->findOrFail($question['revision_id']));
        $this->assertNotEmpty($question['answers']);
        $this->assertNoRealAnswerIds($next->getContent(), $revision->answers->pluck('id'));
        $this->assertEqualsCanonicalizing($this->answerTokens($user, $revision->answers->pluck('id')), array_column($question['answers'], 'id'));

        // Prüfung: alle Fragen eines Bogens.
        $paperId = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->orderBy('paper_number')->value('id'));
        $examId = $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant, ['paper_id' => $paperId])->assertCreated()->json('data.exam_id');
        $exam = $this->api('GET', "/exams/{$examId}", $token, $tenant)->assertOk();
        $questions = collect($exam->json('data.questions'));
        $this->assertGreaterThan(5, $questions->count());
        $revisions = $this->onAdmin(fn () => ContentQuestionRevision::with('answers')->whereIn('id', $questions->pluck('revision_id'))->get()->keyBy('id'));
        $this->assertNoRealAnswerIds($exam->getContent(), $revisions->flatMap(fn ($r) => $r->answers->pluck('id')));

        // Die richtige Antwort ist nicht systematisch die kleinste Kennung.
        $single = $questions->filter(fn ($q) => $q['question_type'] === 'single_choice');
        $this->assertGreaterThan(5, $single->count());
        $correctIsSmallest = $single->filter(function ($q) use ($revisions, $user) {
            $ids = array_column($q['answers'], 'id');
            sort($ids);

            return $ids[0] === $this->answerToken($user, $revisions[$q['revision_id']]->correctAnswer()->id);
        })->count();
        $this->assertLessThan($single->count(), $correctIsSmallest, 'Die kleinste Kennung darf nicht immer die richtige Antwort sein.');
    }

    public function test_two_people_get_different_tokens_for_the_same_answer(): void
    {
        $tenant = $this->createTestTenant();
        $a = $this->createTenantUser($tenant, 'learner');
        $b = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $a, $course);
        $this->grantEntitlement($tenant, $b, $course);
        $paperId = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->orderBy('paper_number')->value('id'));

        $examA = $this->api('POST', "/courses/{$course->id}/exams", $a, $tenant, ['paper_id' => $paperId])->assertCreated()->json('data');
        $examB = $this->api('POST', "/courses/{$course->id}/exams", $b, $tenant, ['paper_id' => $paperId])->assertCreated()->json('data');
        $byRevisionB = collect($examB['questions'])->keyBy('revision_id');
        $compared = 0;
        foreach ($examA['questions'] as $q) {
            if (! isset($byRevisionB[$q['revision_id']]) || $q['answers'] === []) {
                continue;
            }
            $tokensA = array_column($q['answers'], 'id');
            $tokensB = array_column($byRevisionB[$q['revision_id']]['answers'], 'id');
            $this->assertSame([], array_values(array_intersect($tokensA, $tokensB)));
            $compared++;
        }
        $this->assertGreaterThan(0, $compared);
    }

    public function test_api_learning_accepts_own_tokens_only_and_grades_correctly(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $other = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);

        $question = $this->api('GET', "/courses/{$course->id}/trainer/next", $token, $tenant)->assertOk()->json('data.question');
        $revision = $this->onAdmin(fn () => ContentQuestionRevision::with('answers')->findOrFail($question['revision_id']));
        $correct = $revision->answers->firstWhere('is_correct', true);
        $wrong = $revision->answers->firstWhere('is_correct', false);
        $body = ['question_id' => $question['id'], 'revision_id' => $question['revision_id']];

        // Echte ID, fremde Kennung, erfundene Kennung: 422, kein Versuch.
        foreach ([$correct->id, $this->answerToken($other, $correct->id), (string) Str::uuid()] as $bad) {
            $this->api('POST', "/courses/{$course->id}/attempts", $token, $tenant, $body + ['selected_answer_ids' => [$bad]])
                ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED')
                ->assertJsonPath('errors.selected_answer_ids.0', 'Unbekannte Antwort.');
        }
        $this->assertSame(0, $this->onAdmin(fn () => Attempt::where('user_id', $user->id)->count()));

        $right = $this->answerToken($user, $correct->id);
        $this->assertContains($right, array_column($question['answers'], 'id'));
        $result = $this->api('POST', "/courses/{$course->id}/attempts", $token, $tenant, $body + ['selected_answer_ids' => [$right]])
            ->assertCreated()->assertJsonPath('data.correct', true)
            ->assertJsonPath('data.selected_answer_ids', [$right])
            ->assertJsonPath('data.correct_answer_ids', [$right]);
        $this->assertNoRealAnswerIds($result->getContent(), $revision->answers->pluck('id'));
        $this->assertDatabaseHasOnAdmin($user, $correct->id);

        $this->api('POST', "/courses/{$course->id}/attempts", $token, $tenant, $body + ['selected_answer_ids' => [$this->answerToken($user, $wrong->id)]])
            ->assertCreated()->assertJsonPath('data.correct', false)->assertJsonPath('data.correct_answer_ids', [$right]);

        $shown = $this->api('GET', "/courses/{$course->id}/attempts/{$result->json('data.attempt_id')}", $token, $tenant)->assertOk()
            ->assertJsonPath('data.selected_answer_ids', [$right]);
        $this->assertNoRealAnswerIds($shown->getContent(), $revision->answers->pluck('id'));
    }

    public function test_api_exam_accepts_own_tokens_only_and_grades_correctly(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $other = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);
        $paperId = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->orderBy('paper_number')->value('id'));

        $exam = $this->api('POST', "/courses/{$course->id}/exams", $token, $tenant, ['paper_id' => $paperId])->assertCreated()->json('data');
        $q1 = collect($exam['questions'])->firstWhere('question_type', 'single_choice');
        $answers = $this->onAdmin(fn () => ContentAnswer::where('revision_id', $q1['revision_id'])->get());
        $correct = $answers->firstWhere('is_correct', true);

        foreach ([$correct->id, $this->answerToken($other, $correct->id), (string) Str::uuid()] as $bad) {
            $this->api('PUT', "/exams/{$exam['exam_id']}/answers/{$q1['id']}", $token, $tenant, ['selected_answer_ids' => [$bad]])
                ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
        }

        $right = $this->answerToken($user, $correct->id);
        $saved = $this->api('PUT', "/exams/{$exam['exam_id']}/answers/{$q1['id']}", $token, $tenant, ['selected_answer_ids' => [$right]])
            ->assertOk()->assertJsonPath('data.selected_answer_ids', [$right]);
        $this->assertNoRealAnswerIds($saved->getContent(), $answers->pluck('id'));
        // Wiederholung mit derselben Kennung (auch in Großbuchstaben): harmlos.
        $this->api('PUT', "/exams/{$exam['exam_id']}/answers/{$q1['id']}", $token, $tenant, ['selected_answer_ids' => [strtoupper($right)]])->assertOk();
        $this->assertSame($correct->id, $this->onAdmin(fn () => ExamSession::findOrFail($exam['exam_id'])->questions()->where('question_id', $q1['id'])->value('selected_answer_id')));

        $result = $this->api('POST', "/exams/{$exam['exam_id']}/submit", $token, $tenant)->assertOk()->assertJsonPath('data.correct_count', 1);
        $graded = collect($result->json('data.questions'))->firstWhere('id', $q1['id']);
        $this->assertTrue($graded['correct']);
        $this->assertSame([$right], $graded['selected_answer_ids']);
        $this->assertSame([$right], $graded['solution']['correct_answer_ids']);
        $this->assertNoRealAnswerIds($result->getContent(), $answers->pluck('id'));
    }

    public function test_web_learning_page_uses_tokens_and_accepts_only_them(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $other = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $user, $course);
        $revision = $this->onAdmin(fn () => ContentQuestionRevision::where('question_type', 'single_choice')->where('editorial_status', 'published')
            ->whereIn('question_id', $course->contentQuestionIds())->with('answers')->firstOrFail());
        $this->onAdmin(fn () => Favorite::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'question_id' => $revision->question_id, 'context' => Favorite::CONTEXT_SMART_LEARNING]));
        $correct = $revision->correctAnswer();

        $page = $this->actingAsInTenant($user, $tenant)->get(route('learning.show', $course).'?mode=favorites')->assertOk();
        $this->assertNoRealAnswerIds($page->getContent(), $revision->answers->pluck('id'));
        preg_match_all('/name="answer_id" value="([0-9a-f-]{36})"/', $page->getContent(), $m);
        $this->assertEqualsCanonicalizing($this->answerTokens($user, $revision->answers->pluck('id')), $m[1]);

        $store = route('learning.attempts.store', $course);
        foreach ([$correct->id, $this->answerToken($other, $correct->id), (string) Str::uuid()] as $bad) {
            $this->actingAsInTenant($user, $tenant)->post($store, ['revision_id' => $revision->id, 'answer_id' => $bad, 'mode' => 'favorites'])->assertNotFound();
        }

        $response = $this->actingAsInTenant($user, $tenant)->post($store, ['revision_id' => $revision->id, 'answer_id' => $this->answerToken($user, $correct->id), 'mode' => 'favorites']);
        $response->assertRedirect();
        $this->assertDatabaseHasOnAdmin($user, $correct->id, true);

        $shown = $this->actingAsInTenant($user, $tenant)->get($response->headers->get('Location'))->assertOk()->assertSee('Richtig');
        $this->assertNoRealAnswerIds($shown->getContent(), $revision->answers->pluck('id'));
    }

    public function test_web_exam_page_uses_tokens_and_accepts_only_them(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $other = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $user, $course);
        $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->orderBy('paper_number')->firstOrFail());

        $this->actingAsInTenant($user, $tenant)->post(route('exam.papers.start', [$course, $paper]))->assertRedirect();
        $session = $this->asTenant($tenant, fn () => ExamSession::where('user_id', $user->id)->firstOrFail());
        $sq = $this->asTenant($tenant, fn () => $session->questions()->orderBy('position')->with('revision.answers')->firstOrFail());
        $answerIds = $sq->revision->answers->pluck('id');
        $this->assertNotSame('open_text', $sq->revision->question_type, 'Testvoraussetzung: erste Frage mit Antwortauswahl.');

        $page = $this->actingAsInTenant($user, $tenant)->get(route('exam.show', $session))->assertOk();
        $this->assertNoRealAnswerIds($page->getContent(), $answerIds);
        preg_match_all('/name="answer_ids?(?:\[\])?" value="([0-9a-f-]{36})"/', $page->getContent(), $m);
        $this->assertEqualsCanonicalizing($this->answerTokens($user, $answerIds), $m[1]);

        $correct = $sq->revision->correctAnswer();
        $field = $sq->revision->question_type === 'multi_choice' ? 'answer_ids' : 'answer_id';
        $wrap = fn ($v) => $field === 'answer_ids' ? [$v] : $v;
        foreach ([$correct->id, $this->answerToken($other, $correct->id), (string) Str::uuid()] as $bad) {
            $this->actingAsInTenant($user, $tenant)->post(route('exam.answer', $session), ['position' => $sq->position, $field => $wrap($bad)])->assertStatus(422);
        }
        $this->assertNull($this->asTenant($tenant, fn () => $session->questions()->where('position', $sq->position)->value('answered_at')));

        $this->actingAsInTenant($user, $tenant)->post(route('exam.answer', $session), ['position' => $sq->position, $field => $wrap($this->answerToken($user, $correct->id))])
            ->assertRedirect(route('exam.show', $session));
        $this->actingAsInTenant($user, $tenant)->post(route('exam.finish', $session))->assertRedirect(route('exam.result', $session));
        $this->assertTrue($this->asTenant($tenant, fn () => (bool) $session->questions()->where('position', $sq->position)->value('correct')));
    }

    /** @param  iterable<string>  $realIds */
    private function assertNoRealAnswerIds(string $content, iterable $realIds): void
    {
        $count = 0;
        foreach ($realIds as $id) {
            $this->assertStringNotContainsString($id, $content, 'Echte Antwort-ID in der Ausgabe.');
            $count++;
        }
        $this->assertGreaterThan(0, $count);
    }

    private function assertDatabaseHasOnAdmin(User $user, string $answerId, ?bool $correct = null): void
    {
        $attempt = $this->onAdmin(fn () => Attempt::where('user_id', $user->id)->latest('created_at')->first());
        $this->assertNotNull($attempt);
        $this->assertSame($answerId, $attempt->selected_answer_id);
        if ($correct !== null) {
            $this->assertSame($correct, (bool) $attempt->correct);
        }
    }
}
