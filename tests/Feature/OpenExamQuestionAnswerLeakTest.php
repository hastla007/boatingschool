<?php

namespace Tests\Feature;

use App\Models\ExamSession;
use App\Models\ExamSessionQuestion;
use App\Models\Tenant;
use App\Models\User;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review Oberfläche U9: Offene Prüfungsfragen -- die Musterantwort
 * stand bereits im initialen HTML (Alpines x-if="revealed" blendet den
 * Block nur im DOM aus, Blade schreibt den Text aber unabhängig davon in
 * die HTTP-Antwort), und die Selbsteinschätzung ("Gewusst"/"Nicht
 * gewusst", self_assessed_known) wurde als reiner Client-Wert ungeprüft
 * übernommen -- ein per Devtools nachgebauter Request mit known=1 zählte
 * jede offene Frage automatisch als gewusst, ohne die Musterantwort je
 * abgerufen zu haben.
 */
class OpenExamQuestionAnswerLeakTest extends TestCase
{
    use InteractsWithTenants;

    /** @return array{0: ExamSession, 1: ExamSessionQuestion} */
    private function startFknExamWithOpenTextQuestion(): array
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('FKN');
        $this->grantEntitlement($tenant, $learner, $course);

        $paper = $this->onAdmin(fn () => $course->examPapers()->orderBy('paper_number')->firstOrFail());
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}");

        [$session, $openTextQuestion] = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();
            $openTextQuestion = $session->questions()->with('revision.answers')
                ->whereHas('revision', fn ($q) => $q->where('question_type', 'open_text'))
                ->orderBy('position')->firstOrFail();

            return [$session, $openTextQuestion];
        });

        return [$tenant, $learner, $session, $openTextQuestion];
    }

    public function test_the_model_answer_is_not_present_in_the_initial_exam_question_page_html(): void
    {
        [$tenant, $learner, $session, $openTextQuestion] = $this->startFknExamWithOpenTextQuestion();

        // Jede Frage vor der offenen Zielfrage durchlaufen, damit show()
        // exakt diese Frage als aktuelle anzeigt.
        $this->answerAllQuestionsBefore($tenant, $learner, $session, $openTextQuestion->position);

        $correctAnswer = $this->onAdmin(fn () => $openTextQuestion->revision->correctAnswer());
        $this->assertNotNull($correctAnswer, 'Testvoraussetzung: Musterantwort muss existieren.');

        $response = $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}");

        $response->assertOk();
        $response->assertSee('Frage '.$openTextQuestion->position.' von', false);
        $response->assertDontSee($correctAnswer->answer_text, false);
    }

    private function answerAllQuestionsBefore(Tenant $tenant, User $learner, ExamSession $session, int $targetPosition): void
    {
        $questions = $this->asTenant($tenant, fn () => $session->questions()->with('revision.answers')
            ->where('position', '<', $targetPosition)->orderBy('position')->get());

        foreach ($questions as $sq) {
            $type = $sq->revision->question_type;

            if ($type === 'multi_choice') {
                $correctIds = $this->onAdmin(fn () => $sq->revision->correctAnswers()->pluck('id')->values()->all());
                $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                    'position' => $sq->position,
                    'answer_ids' => $correctIds,
                ]);
            } elseif ($type === 'open_text') {
                $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/reveal", [
                    'position' => $sq->position,
                ]);
                $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                    'position' => $sq->position,
                    'known' => true,
                ]);
            } else {
                $correctAnswer = $this->onAdmin(fn () => $sq->revision->correctAnswer());
                $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                    'position' => $sq->position,
                    'answer_id' => $correctAnswer->id,
                ]);
            }
        }
    }

    public function test_reveal_endpoint_returns_the_model_answer_and_marks_it_revealed(): void
    {
        [$tenant, $learner, $session, $openTextQuestion] = $this->startFknExamWithOpenTextQuestion();

        $correctAnswer = $this->onAdmin(fn () => $openTextQuestion->revision->correctAnswer());

        $response = $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/reveal", [
            'position' => $openTextQuestion->position,
        ]);

        $response->assertOk();
        $response->assertJson(['answer_text' => $correctAnswer->answer_text]);

        $this->asTenant($tenant, function () use ($session, $openTextQuestion) {
            $this->assertNotNull($session->questions()->where('position', $openTextQuestion->position)->first()->revealed_at);
        });
    }

    public function test_self_assessment_is_rejected_without_first_revealing_the_model_answer(): void
    {
        [$tenant, $learner, $session, $openTextQuestion] = $this->startFknExamWithOpenTextQuestion();

        // Kein vorheriger Aufruf von /reveal -- genau der per Devtools
        // nachgebaute Request, der bisher jede offene Frage ungeprüft als
        // "gewusst" durchgehen ließ.
        $response = $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
            'position' => $openTextQuestion->position,
            'known' => true,
        ]);

        $response->assertStatus(422);

        $this->asTenant($tenant, function () use ($session, $openTextQuestion) {
            $sq = $session->questions()->where('position', $openTextQuestion->position)->first();
            $this->assertNull($sq->self_assessed_known);
            $this->assertNull($sq->answered_at);
        });
    }

    public function test_self_assessment_is_accepted_after_revealing_the_model_answer(): void
    {
        [$tenant, $learner, $session, $openTextQuestion] = $this->startFknExamWithOpenTextQuestion();

        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/reveal", [
            'position' => $openTextQuestion->position,
        ]);

        $response = $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
            'position' => $openTextQuestion->position,
            'known' => true,
        ]);

        $response->assertRedirect(route('exam.show', $session));

        $this->asTenant($tenant, function () use ($session, $openTextQuestion) {
            $sq = $session->questions()->where('position', $openTextQuestion->position)->first();
            $this->assertTrue($sq->self_assessed_known);
            $this->assertNotNull($sq->answered_at);
        });
    }

    public function test_reveal_is_rejected_for_a_non_open_text_question(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('FKN');
        $this->grantEntitlement($tenant, $learner, $course);

        $paper = $this->onAdmin(fn () => $course->examPapers()->orderBy('paper_number')->firstOrFail());
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}");

        [$session, $nonOpenTextQuestion] = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();
            $nonOpenTextQuestion = $session->questions()->with('revision')
                ->whereHas('revision', fn ($q) => $q->where('question_type', '!=', 'open_text'))
                ->orderBy('position')->firstOrFail();

            return [$session, $nonOpenTextQuestion];
        });

        $response = $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/reveal", [
            'position' => $nonOpenTextQuestion->position,
        ]);

        $response->assertStatus(422);
    }
}
