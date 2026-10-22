<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\ExamBlueprint;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B32: eine Prüfungssession wird nur ausgewertet, wenn der
 * Lerner später noch einmal mit ihr interagiert (show()/answer()/finish()
 * in ExamController) -- schließt jemand nach dem Start einfach den Tab,
 * bleibt status="running" für immer stehen. Der neue Sweep
 * (app:expire-abandoned-exam-sessions) wertet solche Sessions nachträglich
 * aus: bei einem Zeitlimit sobald es verstrichen ist, sonst nach einer
 * technischen Kulanzfrist (siehe ExpireAbandonedExamSessions).
 */
class ExpireAbandonedExamSessionsTest extends TestCase
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

    public function test_an_abandoned_session_past_its_time_limit_is_evaluated(): void
    {
        [$tenant, $learner, $course] = $this->setUpExamCourse(timeLimitSeconds: 1800);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();

        $this->onAdmin(fn () => $session->update(['started_at' => now()->subMinutes(31)]));

        Artisan::call('app:expire-abandoned-exam-sessions');

        $this->assertSame('evaluated', $session->fresh()->status);
    }

    public function test_an_abandoned_session_without_a_time_limit_is_evaluated_after_the_grace_period(): void
    {
        [$tenant, $learner, $course] = $this->setUpExamCourse(timeLimitSeconds: null);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();

        $this->onAdmin(fn () => $session->update(['started_at' => now()->subHours(25)]));

        Artisan::call('app:expire-abandoned-exam-sessions');

        $this->assertSame('evaluated', $session->fresh()->status);
    }

    public function test_a_recently_started_session_is_left_running(): void
    {
        [$tenant, $learner, $course] = $this->setUpExamCourse(timeLimitSeconds: 1800);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();

        Artisan::call('app:expire-abandoned-exam-sessions');

        $this->assertSame('running', $session->fresh()->status);
    }

    /** @return array{0: \App\Models\Tenant, 1: User, 2: CourseDefinition} */
    private function setUpExamCourse(?int $timeLimitSeconds): array
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
