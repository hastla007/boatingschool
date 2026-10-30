<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\Progress;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review X11 (Doppelklick auf "Antworten" zählt doppelt, Frage zu
 * schnell "gefestigt"): LearningService::recordAttempt() legte bisher pro
 * Request bedingungslos einen neuen Attempt an und erhöhte current_streak/
 * mastery_score auf der Progress-Zeile -- der bereits bestehende
 * Doppelklick-Schutz (Bug-Review N2) verhindert zwar den HTTP 500 aus dem
 * Primary-Key-Konflikt der Progress-Zeile beim allerersten Attempt zu einer
 * Frage, lässt aber beide nahezu gleichzeitigen Requests ihren eigenen
 * Attempt anlegen und den Streak jeweils ein zweites Mal erhöhen. Ein
 * Doppelklick auf eine richtige Antwort zählte dadurch als zwei richtige
 * Antworten in Folge, wodurch "gefestigt" (current_streak >= 3) nach nur
 * zwei tatsächlichen Klicks statt drei erreicht wurde.
 *
 * started_at_ms identifiziert genau den einen Seitenaufruf, aus dem das
 * Formular stammt (siehe LearningController::show(), 'startedAt' =>
 * now()->valueOf()) -- ein echter Doppelklick sendet denselben Wert zweimal
 * im selben POST-Body, ein bewusst neu geladener/erneut beantworteter
 * Durchlauf bekommt einen neuen Wert und bleibt davon unberührt (siehe
 * zweiter Test unten).
 */
class DoubleClickDoesNotDoubleCountLearningAttemptTest extends TestCase
{
    use InteractsWithTenants;

    public function test_submitting_the_same_answer_twice_with_the_same_started_at_ms_only_counts_once(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SBF_BASIS');
        [$question, $revision] = $this->anyPublishedQuestionIn($module);
        $correctAnswer = $revision->answers->firstWhere('is_correct', true);

        $startedAtMs = (int) round(microtime(true) * 1000) - 2000;
        $payload = [
            'revision_id' => $revision->id,
            'answer_id' => $correctAnswer->id,
            'mode' => 'smarttrainer',
            'started_at_ms' => $startedAtMs,
        ];

        $first = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", $payload);
        $first->assertRedirect();
        $first->assertSessionHasNoErrors();

        $second = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", $payload);
        $second->assertRedirect();
        $second->assertSessionHasNoErrors();
        $this->assertNotSame(500, $second->getStatusCode());

        $this->asTenant($tenant, function () use ($tenant, $learner, $question) {
            $this->assertSame(
                1,
                Attempt::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('question_id', $question->id)->count(),
                'Ein per Doppelklick doppelt abgeschickter Attempt darf nur einmal gespeichert werden.'
            );

            $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('question_id', $question->id)->first();
            $this->assertSame(1, $progress->current_streak, 'Der Streak darf durch einen Doppelklick nur einmal erhöht werden.');
            $this->assertSame(1, $progress->attempt_count);
        });
    }

    /**
     * Gegenprobe: zwei tatsächlich unterschiedliche Antworten (z. B. ein
     * bewusst erneut geladener Durchlauf mit neuem started_at_ms) müssen
     * weiterhin beide gezählt werden -- der Fix darf keine echten
     * Mehrfachversuche unterdrücken.
     */
    public function test_submitting_the_same_question_twice_with_different_started_at_ms_counts_both(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SBF_BASIS');
        [$question, $revision] = $this->anyPublishedQuestionIn($module);
        $correctAnswer = $revision->answers->firstWhere('is_correct', true);

        $basePayload = [
            'revision_id' => $revision->id,
            'answer_id' => $correctAnswer->id,
            'mode' => 'smarttrainer',
        ];

        $first = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", $basePayload + [
            'started_at_ms' => (int) round(microtime(true) * 1000) - 5000,
        ]);
        $first->assertRedirect();

        $second = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", $basePayload + [
            'started_at_ms' => (int) round(microtime(true) * 1000) - 2000,
        ]);
        $second->assertRedirect();

        $this->asTenant($tenant, function () use ($tenant, $learner, $question) {
            $this->assertSame(
                2,
                Attempt::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('question_id', $question->id)->count()
            );

            $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->where('question_id', $question->id)->first();
            $this->assertSame(2, $progress->current_streak);
        });
    }
}
