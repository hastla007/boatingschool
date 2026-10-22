<?php

namespace Tests\Feature;

use App\Models\Attempt;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B19: die Antwortzeit wurde bisher clientseitig als
 * Date.now() - $startedAt berechnet (beide Werte aus der Sicht des
 * Clients) und per 'response_time_ms' => ['min:0'] validiert. Eine
 * nachgehende (zu langsame) Geräteuhr lieferte dadurch einen negativen
 * Wert, der die GESAMTE Antwort als ungültig (422) ablehnte -- der Lerner
 * konnte die Frage dann gar nicht beantworten. started_at_ms trägt nur den
 * unveränderten, vom Server gerenderten Zeitpunkt zurück; die Dauer wird
 * serverseitig aus der Differenz zur eigenen Uhr berechnet und ist damit
 * unabhängig von der Geräteuhr des Lerners.
 */
class ResponseTimeClockSkewTest extends TestCase
{
    use InteractsWithTenants;

    public function test_a_tampered_started_at_in_the_future_does_not_reject_the_answer(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SBF_BASIS');
        [$question, $revision] = $this->anyPublishedQuestionIn($module);
        $correctAnswer = $revision->answers->firstWhere('is_correct', true);

        // Entspricht dem, was eine nachgehende Geräteuhr im alten,
        // clientseitig berechneten Modell bewirkt hätte: der "Startzeitpunkt"
        // liegt (aus Sicht der Berechnung) nach dem Submit-Zeitpunkt.
        $startedAtInTheFuture = (int) round(microtime(true) * 1000) + 60_000;

        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", [
            'revision_id' => $revision->id,
            'answer_id' => $correctAnswer->id,
            'mode' => 'smarttrainer',
            'started_at_ms' => $startedAtInTheFuture,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $attempt = Attempt::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->where('question_id', $question->id)->latest('created_at')->first();

        $this->assertNotNull($attempt);
        $this->assertTrue($attempt->correct);
        $this->assertSame(0, $attempt->response_time_ms);
    }

    public function test_response_time_is_computed_from_the_servers_own_clock(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SBF_BASIS');
        [$question, $revision] = $this->anyPublishedQuestionIn($module);
        $correctAnswer = $revision->answers->firstWhere('is_correct', true);

        $startedAt = (int) round(microtime(true) * 1000) - 5_000;

        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", [
            'revision_id' => $revision->id,
            'answer_id' => $correctAnswer->id,
            'mode' => 'smarttrainer',
            'started_at_ms' => $startedAt,
        ]);

        $response->assertRedirect();

        $attempt = Attempt::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->where('question_id', $question->id)->latest('created_at')->first();

        $this->assertNotNull($attempt);
        $this->assertGreaterThanOrEqual(5_000, $attempt->response_time_ms);
        $this->assertLessThan(15_000, $attempt->response_time_ms);
    }
}
