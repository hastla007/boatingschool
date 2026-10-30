<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\ContentQuestionRevision;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Fehlerseite bei Antwort auf eine länger als 25 Tage offene
 * Frage): attempt.response_time_ms war als "integer" (4 Byte, max.
 * 2.147.483.647 ms ≈ 24 Tage 20 Stunden) angelegt -- eine Frage, die
 * länger offen blieb, erzeugte beim Speichern einen Wert über diesem
 * Limit, der INSERT scheiterte mit einer unabgefangenen Postgres-Exception
 * (HTTP 500) statt die Antwort normal zu speichern. Jetzt "bigint" (siehe
 * Migration 2024_02_01_000478).
 */
class LearningResponseTimeBeyond25DaysTest extends TestCase
{
    use InteractsWithTenants;

    public function test_answering_a_question_opened_more_than_25_days_ago_does_not_crash(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $courseQuestionIds = $this->onAdmin(fn () => $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id'));
        $revision = $this->onAdmin(fn () => ContentQuestionRevision::where('editorial_status', 'published')
            ->where('question_type', 'single_choice')
            ->whereIn('question_id', $courseQuestionIds)
            ->with('answers')->first());
        $this->assertNotNull($revision, 'Erwartet eine veröffentlichte single_choice-Frage im SBF-SEE-Kurs.');

        // 26 Tage in Millisekunden, deutlich über dem alten int32-Limit von
        // rund 24 Tagen 20 Stunden.
        $startedAtMs = (int) round(microtime(true) * 1000) - (26 * 24 * 60 * 60 * 1000);

        $response = $this->actingAsInTenant($learner, $tenant)
            ->followingRedirects()
            ->post('/courses/'.$course->id.'/learn/attempts', [
                'revision_id' => $revision->id,
                'mode' => 'smarttrainer',
                'answer_id' => $revision->answers->firstWhere('is_correct', true)->id,
                'started_at_ms' => $startedAtMs,
            ]);

        $response->assertOk();

        $attempt = $this->onAdmin(fn () => Attempt::where('revision_id', $revision->id)
            ->where('user_id', $learner->id)->latest('created_at')->first());

        $this->assertNotNull($attempt);
        $this->assertGreaterThan(2_147_483_647, $attempt->response_time_ms);
    }
}
