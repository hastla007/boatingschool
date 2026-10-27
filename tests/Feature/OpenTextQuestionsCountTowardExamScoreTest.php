<?php

namespace Tests\Feature;

use App\Models\ExamSession;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Hoch): ExamEvaluationService::evaluate() zählte open_text-
 * Fragen bisher GAR NICHT in $scoredTotal/$correctCount -- die
 * Bestehensquote bezog sich dadurch nur auf die automatisch auswertbaren
 * Fragen eines Bogens. Die amtlichen FKN-Prüfungsbögen (siehe
 * FknExamPaperSeeder, 15 Fragen/Bogen, davon nur 9-10 Nicht-open_text)
 * haben amtlich eine Bestehensgrenze von 24 von 30 Punkten = 12 von 15
 * (80%) über ALLE 15 Fragen (siehe ExamRuleSetSeeder) -- durch den
 * Ausschluss wurde diese Grenze faktisch zu "8 von 9" bzw. "8 von 10" auf
 * der verbleibenden Teilmenge, je nach Bogen unterschiedlich.
 */
class OpenTextQuestionsCountTowardExamScoreTest extends TestCase
{
    use InteractsWithTenants;

    public function test_open_text_answers_count_toward_the_fkn_exam_score_and_denominator(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('FKN');
        $this->grantEntitlement($tenant, $learner, $course);

        $paper = $this->onAdmin(fn () => $course->examPapers()->orderBy('paper_number')->firstOrFail());

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}");

        [$session, $sessionQuestions] = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();
            $sessionQuestions = $session->questions()->orderBy('position')->with('revision.answers')->get();

            return [$session, $sessionQuestions];
        });

        $total = $sessionQuestions->count();
        $openTextCount = $sessionQuestions->filter(fn ($sq) => $sq->revision->question_type === 'open_text')->count();
        $this->assertGreaterThan(0, $openTextCount, 'Testvoraussetzung: der Bogen muss mindestens eine offene Frage enthalten.');

        foreach ($sessionQuestions as $sq) {
            $type = $sq->revision->question_type;

            if ($type === 'multi_choice') {
                $correctIds = $sq->revision->correctAnswers()->pluck('id')->values()->all();
                $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                    'position' => $sq->position,
                    'answer_ids' => $correctIds,
                ]);
            } elseif ($type === 'open_text') {
                // Bewusst als "nicht gewusst" eingeschätzt -- diese Antworten
                // müssen jetzt messbar die Quote senken, nicht mehr folgenlos
                // bleiben.
                $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                    'position' => $sq->position,
                    'known' => false,
                ]);
            } else {
                $correctAnswer = $sq->revision->correctAnswer();
                $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                    'position' => $sq->position,
                    'answer_id' => $correctAnswer->id,
                ]);
            }
        }

        $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}");

        $evaluated = $this->onAdmin(fn () => ExamSession::find($session->id));

        $nonOpenTextCount = $total - $openTextCount;
        $expectedScore = round($nonOpenTextCount / $total, 4);

        $this->assertEquals(
            $expectedScore,
            $evaluated->score,
            'Die als "nicht gewusst" eingeschätzten offenen Fragen müssen die Quote senken -- vorher blieben sie komplett außen vor und die Quote bezog sich nur auf die Multiple-Choice-Teilmenge.'
        );
        $this->assertLessThan(1.0, $evaluated->score, 'Score darf nicht 100% sein, wenn ein Teil der Fragen als falsch eingeschätzt wurde.');
    }
}
