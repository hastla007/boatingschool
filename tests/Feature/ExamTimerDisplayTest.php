<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\ExamBlueprint;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\Tenant;
use App\Models\User;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B23/B24: gmdate('i:s', $remainingSeconds) interpretiert den
 * Wert als Unix-Timestamp -- bei genau 60 Minuten (3600s) oder mehr springt
 * "i" (Minute der aktuellen STUNDE, 0-59) auf 0, die Anzeige zeigte also
 * "00:00" statt z. B. "89:59" bei einem 90-Minuten-Limit. Zusätzlich ist
 * diffInSeconds() ein float mit Mikrosekunden-Bruchteil, was beim
 * ungecasteten Durchreichen an gmdate() eine Deprecation-Warnung auslöste.
 */
class ExamTimerDisplayTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdRuleSetIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenants();

        $this->onAdmin(function () {
            foreach ($this->createdRuleSetIds as $id) {
                ExamRuleSet::where('id', $id)->delete();
            }
        });

        $this->cleanUpCreatedUsers();

        parent::tearDown();
    }

    public function test_the_timer_shows_minutes_beyond_sixty_instead_of_wrapping_to_zero(): void
    {
        [$tenant, $learner, $course] = $this->setUpExamCourseWithTimeLimit(5400); // 90 Minuten

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        $session = $this->asTenant($tenant, fn () => ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail());

        $response = $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}");

        $response->assertOk();
        $response->assertDontSee('>00:00<', false);
        // Direkt nach dem Start sind von 90 Minuten noch (knapp) 89 Minuten
        // übrig -- die Minutenanzeige muss also bei "89" liegen, nicht beim
        // Rest einer vollen Stunde ("29", 90 % 60).
        $response->assertSee('id="exam-timer"', false);
        preg_match('/id="exam-timer"[^>]*>(\d{2}):(\d{2})</', $response->getContent(), $matches);
        $this->assertNotEmpty($matches, 'Timer-Element nicht gefunden.');
        $this->assertSame('89', $matches[1]);
    }

    /** @return array{0: Tenant, 1: User, 2: CourseDefinition} */
    private function setUpExamCourseWithTimeLimit(int $timeLimitSeconds): array
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('UBI');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('UBI');
        $reviewer = $this->createTenantUser($tenant, 'admin');

        $this->onAdmin(function () use ($course, $module, $reviewer, $timeLimitSeconds) {
            $ruleSet = ExamRuleSet::create([
                'course_id' => $course->id,
                'version' => 'test-'.uniqid(),
                'time_limit_seconds' => $timeLimitSeconds,
                'passing_rule' => ['min_correct_ratio' => 0.75],
                'status' => 'published',
                'verified_by' => $reviewer->id,
                'verified_at' => now(),
            ]);

            ExamBlueprint::create([
                'rule_set_id' => $ruleSet->id,
                'module_id' => $module->id,
                'question_count' => 3,
            ]);

            $this->createdRuleSetIds[] = $ruleSet->id;
        });

        return [$tenant, $learner, $course];
    }
}
