<?php

namespace Tests\Feature;

use App\Models\ExamSession;
use App\Models\User;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (FKN offene Fragen): offene Fragen (open_text) fließen seit
 * Bug-Review "Hoch" per Selbsteinschätzung in die Prüfungsquote ein (siehe
 * OpenTextQuestionsCountTowardExamScoreTest) -- ohne automatische
 * Freitext-Prüfung bleibt das manipulierbar. Das Ergebnis selbst wies das
 * bisher nirgends aus; dieser Test prüft, dass die drei Stellen, an denen
 * ein Lernender oder Superadmin ein Prüfungsergebnis einsieht (Ergebnis-
 * seite, Fortschrittsseite, Superadmin-Nutzerdetail), jetzt ausweisen, wie
 * viele Punkte auf Selbsteinschätzung beruhen.
 */
class ExamResultDisclosesSelfAssessedOpenTextTest extends TestCase
{
    use InteractsWithTenants;

    public function test_exam_result_and_progress_page_disclose_self_assessed_open_text_points(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('FKN');
        $this->grantEntitlement($tenant, $learner, $course);

        $paper = $this->onAdmin(fn () => $course->examPapers()->orderBy('paper_number')->firstOrFail());

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}");

        [$session, $sessionQuestions] = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();
            $sessionQuestions = $session->questions()->orderBy('position')->with('revision.answers')->get();

            return [$session, $sessionQuestions];
        });

        $openTextCount = $sessionQuestions->filter(fn ($sq) => $sq->revision->question_type === 'open_text')->count();
        $this->assertGreaterThan(0, $openTextCount, 'Testvoraussetzung: der FKN-Bogen muss mindestens eine offene Frage enthalten.');

        foreach ($sessionQuestions as $sq) {
            $type = $sq->revision->question_type;

            if ($type === 'multi_choice') {
                $correctIds = $sq->revision->correctAnswers()->pluck('id')->values()->all();
                $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                    'position' => $sq->position,
                    'answer_ids' => $correctIds,
                ]);
            } elseif ($type === 'open_text') {
                $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/reveal", [
                    'position' => $sq->position,
                ]);
                // Eine der offenen Fragen bewusst als "gewusst" eingeschätzt,
                // damit openTextCorrect sich von 0 UND von openTextCount
                // unterscheidet -- ein Test, der beide Werte gleich lässt,
                // würde eine vertauschte Zuordnung nicht aufdecken.
                $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                    'position' => $sq->position,
                    'known' => $sq->position === $sessionQuestions->firstWhere('revision.question_type', 'open_text')->position,
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

        $expectedCorrect = $openTextCount > 0 ? 1 : 0;

        $resultResponse = $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}/result");
        $resultResponse->assertOk();
        $resultResponse->assertSee("{$expectedCorrect}/{$openTextCount} Punkte aus offenen Fragen durch Selbsteinschätzung");

        $progressResponse = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/progress");
        $progressResponse->assertOk();
        $progressResponse->assertSee("{$expectedCorrect}/{$openTextCount} Punkte aus offenen Fragen durch Selbsteinschätzung");

        $learnerModel = $this->onAdmin(fn () => User::findOrFail($learner->id));
        $superadminResponse = $this->actingAs($this->createSuperAdmin())->get("/superadmin/users/{$learnerModel->id}");
        $superadminResponse->assertOk();
        $superadminResponse->assertSee("{$expectedCorrect}/{$openTextCount} Punkte durch Selbsteinschätzung");
    }
}
