<?php

namespace Tests\Feature;

use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\ExamSessionQuestion;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VideoLesson;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Hoch): LearningController::storeAttempt() und
 * VideoQuizController::answer() blockierten eine Frage (409), solange
 * irgendeine Prüfungssession dieses Nutzers mit status='running' sie
 * enthielt -- ohne zu prüfen, ob die Prüfungszeit bereits abgelaufen war.
 * Eine Prüfungssession bleibt in der DB aber so lange "running", bis sie
 * ausgewertet wird (ExamController tut das nur, wenn der Nutzer die
 * Prüfungsseite selbst erneut aufruft) -- ein Nutzer, der eine zeitlich
 * abgelaufene Prüfung einfach liegen ließ, sah jede ihrer Fragen im
 * Smarttrainer/Video-Quiz PERMANENT gesperrt, auch lange nach Ablauf der
 * Prüfungszeit.
 */
class RunningExamNoLongerBlocksLearningAfterTimeExpiredTest extends TestCase
{
    use InteractsWithTenants;

    private function startExamSession(Tenant $tenant, User $learner, $course, $question, $revision, \DateTimeInterface $startedAt): ExamSession
    {
        return $this->onAdmin(function () use ($tenant, $learner, $course, $question, $revision, $startedAt) {
            $ruleSet = ExamRuleSet::create([
                'course_id' => $course->id,
                'version' => 'test-'.uniqid(),
                'time_limit_seconds' => 1800,
                'passing_rule' => ['min_correct_ratio' => 0.75],
                'status' => 'published',
            ]);

            $session = ExamSession::create([
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'course_id' => $course->id,
                'rule_set_id' => $ruleSet->id,
                'status' => 'running',
                'started_at' => $startedAt,
            ]);

            ExamSessionQuestion::create([
                'tenant_id' => $tenant->id,
                'exam_session_id' => $session->id,
                'question_id' => $question->id,
                'revision_id' => $revision->id,
                'position' => 1,
            ]);

            return $session;
        });
    }

    public function test_smarttrainer_still_blocks_a_question_from_a_genuinely_running_exam(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SBF_BASIS');
        [$question, $revision] = $this->anyPublishedQuestionIn($module);
        $this->startExamSession($tenant, $learner, $course, $question, $revision, now());

        $wrongAnswer = $revision->answers->firstWhere('is_correct', false);
        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", [
            'revision_id' => $revision->id,
            'answer_id' => $wrongAnswer->id,
            'mode' => 'smarttrainer',
        ]);

        $response->assertStatus(409);
    }

    public function test_smarttrainer_no_longer_blocks_a_question_from_an_exam_whose_time_limit_has_expired(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SBF_BASIS');
        [$question, $revision] = $this->anyPublishedQuestionIn($module);
        // 1800s Zeitlimit, Start vor einer Stunde -- die Session steht in
        // der DB immer noch auf "running", weil niemand die Prüfungsseite
        // seitdem erneut aufgerufen hat (genau das macht den Bug aus).
        $session = $this->startExamSession($tenant, $learner, $course, $question, $revision, now()->subHour());

        $wrongAnswer = $revision->answers->firstWhere('is_correct', false);
        $response = $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", [
            'revision_id' => $revision->id,
            'answer_id' => $wrongAnswer->id,
            'mode' => 'smarttrainer',
        ]);

        $response->assertRedirect();
        $this->assertSame('evaluated', $this->onAdmin(fn () => $session->fresh()->status), 'Eine abgelaufene Session muss nebenbei ausgewertet werden, statt weiterhin als blockierend zu gelten.');
    }

    private function findLessonWithQuestions($course): VideoLesson
    {
        return $this->onAdmin(function () use ($course) {
            foreach ($course->videoModules()->with('lessons.questions')->get() as $module) {
                foreach ($module->lessons as $lesson) {
                    if ($lesson->questions->isNotEmpty()) {
                        return $lesson;
                    }
                }
            }

            throw new \RuntimeException('Kein Video-Kapitel mit Fragen gefunden.');
        });
    }

    public function test_video_quiz_still_blocks_a_question_from_a_genuinely_running_exam(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $lesson = $this->findLessonWithQuestions($course);
        $question = $this->onAdmin(fn () => $lesson->questions->first());
        $revision = $this->onAdmin(fn () => $question->publishedRevision()->load('answers'));
        $this->startExamSession($tenant, $learner, $course, $question, $revision, now());

        $answer = $revision->answers->first();
        $response = $this->actingAsInTenant($learner, $tenant)->post(
            "/courses/{$course->id}/video/{$lesson->id}/questions/1",
            ['answer_id' => $answer->id]
        );

        $response->assertStatus(409);
    }

    public function test_video_quiz_no_longer_blocks_a_question_from_an_exam_whose_time_limit_has_expired(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $lesson = $this->findLessonWithQuestions($course);
        $question = $this->onAdmin(fn () => $lesson->questions->first());
        $revision = $this->onAdmin(fn () => $question->publishedRevision()->load('answers'));
        $session = $this->startExamSession($tenant, $learner, $course, $question, $revision, now()->subHour());

        $answer = $revision->answers->first();
        $response = $this->actingAsInTenant($learner, $tenant)->followingRedirects()->post(
            "/courses/{$course->id}/video/{$lesson->id}/questions/1",
            ['answer_id' => $answer->id]
        );

        $response->assertOk();
        $this->assertSame('evaluated', $this->onAdmin(fn () => $session->fresh()->status), 'Eine abgelaufene Session muss nebenbei ausgewertet werden, statt weiterhin als blockierend zu gelten.');
    }
}
