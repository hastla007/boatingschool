<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\ExamBlueprint;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\Tenant;
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
        $session = $this->asTenant($tenant, fn () => ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail());

        // Statt $session->update(...): eine zuvor geladene Model-Instanz
        // trägt die Connection fest, die beim Laden "default" war (hier:
        // die App-Rolle) -- onAdmin() schaltet nur die KONFIGURATION um,
        // ein Aufruf auf dieser Instanz würde also trotzdem (unter RLS,
        // ohne gesetzten Tenant-Kontext) ins Leere laufen und 0 Zeilen
        // treffen, obwohl update()/save() trotzdem true zurückgibt. Der
        // statische Query-Builder-Stil baut dagegen bei jedem Aufruf neu
        // auf der gerade aktuellen Default-Verbindung auf.
        $this->onAdmin(fn () => ExamSession::where('id', $session->id)->update(['started_at' => now()->subMinutes(31)]));

        Artisan::call('app:expire-abandoned-exam-sessions');

        $session = $this->asTenant($tenant, fn () => ExamSession::find($session->id));
        $this->assertSame('evaluated', $session->status);
    }

    public function test_an_abandoned_session_without_a_time_limit_is_evaluated_after_the_grace_period(): void
    {
        [$tenant, $learner, $course] = $this->setUpExamCourse(timeLimitSeconds: null);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        $session = $this->asTenant($tenant, fn () => ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail());

        $this->onAdmin(fn () => ExamSession::where('id', $session->id)->update(['started_at' => now()->subHours(25)]));

        Artisan::call('app:expire-abandoned-exam-sessions');

        $session = $this->asTenant($tenant, fn () => ExamSession::find($session->id));
        $this->assertSame('evaluated', $session->status);
    }

    public function test_a_recently_started_session_is_left_running(): void
    {
        [$tenant, $learner, $course] = $this->setUpExamCourse(timeLimitSeconds: 1800);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        $session = $this->asTenant($tenant, fn () => ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail());

        Artisan::call('app:expire-abandoned-exam-sessions');

        $session = $this->asTenant($tenant, fn () => ExamSession::find($session->id));
        $this->assertSame('running', $session->status);
    }

    /** @return array{0: Tenant, 1: User, 2: CourseDefinition} */
    private function setUpExamCourse(?int $timeLimitSeconds): array
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('ADD-UBI-TO-SRC');
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
