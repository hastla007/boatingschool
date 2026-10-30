<?php

namespace Tests\Feature;

use App\Models\ExamBlueprint;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use PDO;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Zwei gleichzeitige Prüfungsauswertungen stören sich):
 * ExamEvaluationService::evaluate() prüfte den Status bisher nur per
 * refresh() ohne Sperre, und ExamController::answer() prüfte ihn ganz ohne
 * erneute Prüfung kurz vor dem eigentlichen Speichern der Antwort. Lief
 * eine Auswertung (finish() oder der serverseitige Zeit-Timeout-Zweig in
 * answer()) nebenläufig zu einem zweiten answer()-Request für dieselbe
 * Session, konnte die Antwort noch auf eine bereits bewertete Frage
 * geschrieben werden -- ohne die bereits feststehende Bewertung
 * (correct) zu beeinflussen, aber mit einer fälschlich als "beantwortet"
 * stehenden, inzwischen irrelevanten Zeile.
 */
class ExamEvaluationRaceTest extends TestCase
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

    /**
     * Um das ohne eine künstlich "unechte" rein sequenzielle zweite
     * Anfrage zu testen (die würde die bereits committete Auswertung
     * einfach vorfinden und gar nicht in das verwundbare Zeitfenster
     * treffen, siehe DoubleSubmitRaceTest), simuliert ein echter
     * Kindprozess (pcntl_fork) eine nebenläufige Auswertung: er sperrt
     * dieselbe exam_session-Zeile wie ExamEvaluationService::evaluate()
     * und hält sie absichtlich kurz offen, bevor er den Status auf
     * "evaluated" setzt und committet -- genau das Zeitfenster, das ein
     * echtes Zusammentreffen von answer() und evaluate() erzeugt.
     */
    public function test_answering_while_a_concurrent_evaluation_is_in_flight_does_not_write_a_stale_answer(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix-Erweiterung nicht verfügbar.');
        }

        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('ADD-UBI-TO-SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('UBI');
        $reviewer = $this->createTenantUser($tenant, 'admin');

        $ruleSet = $this->onAdmin(function () use ($course, $module, $reviewer) {
            $ruleSet = ExamRuleSet::create([
                'course_id' => $course->id,
                'version' => 'test-'.uniqid(),
                'time_limit_seconds' => 3600,
                'passing_rule' => ['min_correct_ratio' => 0.75],
                'status' => 'published',
                'verified_by' => $reviewer->id,
                'verified_at' => now(),
            ]);

            ExamBlueprint::create(['rule_set_id' => $ruleSet->id, 'module_id' => $module->id, 'question_count' => 3]);

            $this->createdRuleSetIds[] = $ruleSet->id;

            return $ruleSet;
        });

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam");
        [$session, $question, $answer] = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();
            $question = $session->questions()->orderBy('position')->with('revision.answers')->firstOrFail();

            return [$session, $question, $question->revision->answers->first()];
        });

        $adminConfig = config('database.connections.pgsql_admin');

        $pid = pcntl_fork();

        if ($pid === 0) {
            // Kindprozess: sperrt exakt dieselbe exam_session-Zeile wie
            // ExamEvaluationService::evaluate() und hält sie offen, bevor
            // er die Session als ausgewertet markiert.
            try {
                $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $adminConfig['host'], $adminConfig['port'], $adminConfig['database']);
                $pdo = new PDO($dsn, $adminConfig['username'], $adminConfig['password']);
                $pdo->beginTransaction();
                $pdo->prepare('SELECT id FROM exam_session WHERE id = :id FOR UPDATE')->execute(['id' => $session->id]);
                $pdo->query('SELECT pg_sleep(0.4)');
                $pdo->prepare("UPDATE exam_session SET status = 'evaluated', submitted_at = now(), score = 0, passed = false WHERE id = :id")
                    ->execute(['id' => $session->id]);
                $pdo->commit();
            } finally {
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        // Dem Kindprozess Zeit geben, die exam_session-Zeile zu sperren,
        // bevor der eigentliche answer()-Request genau in dieses
        // Zeitfenster trifft.
        usleep(150_000);

        $response = $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
            'position' => $question->position,
            'answer_id' => $answer->id,
        ]);

        pcntl_waitpid($pid, $status);

        $response->assertStatus(409);

        $this->asTenant($tenant, function () use ($session, $question) {
            $this->assertDatabaseHas('exam_session_question', [
                'exam_session_id' => $session->id,
                'position' => $question->position,
                'selected_answer_id' => null,
                'answered_at' => null,
            ]);
        });
    }
}
