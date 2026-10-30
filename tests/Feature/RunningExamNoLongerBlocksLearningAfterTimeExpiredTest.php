<?php

namespace Tests\Feature;

use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\ExamSessionQuestion;
use App\Models\Favorite;
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

    // Bug-Review (Tests lassen Daten liegen -- veröffentlichte Regelwerke
    // auf dem echten Kurs SBF-SEE): dieser Klasse fehlte bisher jede
    // tearDown() -- jeder der vier Tests legte über startExamSession() ein
    // veröffentlichtes ExamRuleSet auf dem ECHTEN, geteilten SBF-SEE-Kurs an
    // (course_id gehört zum Kurs, nicht zum Tenant, und wird daher NICHT
    // durch das Löschen des Test-Tenants kaskadiert) und ließ es dort
    // dauerhaft stehen -- ebenso die über createTestTenant()/
    // createTenantUser() angelegten Tenants/Nutzer.
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
            $this->createdRuleSetIds[] = $ruleSet->id;

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

    /**
     * Bug-Review (Smarttrainer blockiert während laufender Prüfung):
     * nextQuestion() bot eine Frage, die Teil dieser laufenden Session
     * ist, bisher trotzdem aktiv als nächste Lernfrage an -- storeAttempt()
     * lehnte die anschließend eingereichte Antwort dann mit 409 ab (siehe
     * Test oben), statt von vornherein eine andere Frage auszuspielen.
     */
    public function test_smarttrainer_leaves_a_question_from_a_genuinely_running_exam_out_of_the_selection(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SBF_BASIS');
        [$question, $revision] = $this->anyPublishedQuestionIn($module);
        $this->startExamSession($tenant, $learner, $course, $question, $revision, now());

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/learn?mode=smarttrainer");

        $response->assertOk();
        $response->assertViewHas('revision', fn ($shown) => $shown->question_id !== $question->id);
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

    /**
     * Bug-Review X10 (Favoriten zeigen Fragen aus laufender Prüfung,
     * Absenden schlägt fehl): storeAttempt() lehnt eine Antwort auf eine
     * Frage ab, die Teil einer noch laufenden Prüfungssession dieses
     * Nutzers ist (siehe Test oben) -- der Favoriten-Modus bot genau so
     * eine Frage bisher trotzdem aktiv an und scheiterte erst beim
     * anschließenden Abschicken mit 409, analog zum bereits für den
     * Smarttrainer behobenen Bug-Review "Smarttrainer blockiert während
     * laufender Prüfung". Die Auswahl hier erfolgt über inRandomOrder(),
     * daher ist der EINZIGE Favorit in diesem Test blockiert: ohne Fix
     * bleibt er trotzdem der einzige -- und damit immer gewählte --
     * Kandidat (siehe Test unten), mit Fix bleibt kein Kandidat mehr
     * übrig und die "keine Favoriten"-Ansicht erscheint, analog zur
     * bereits bestehenden Bug-Review "Deaktivierte Favoriten führen zu
     * Endlosschleife" für denselben Sonderfall (einziger Favorit
     * ausgeschlossen).
     */
    public function test_favorites_leave_a_question_from_a_genuinely_running_exam_out_of_the_selection(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $module = $this->existingModule('SBF_BASIS');
        [$blockedQuestion, $blockedRevision] = $this->anyPublishedQuestionIn($module);

        $this->onAdmin(fn () => Favorite::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'question_id' => $blockedQuestion->id,
            'context' => Favorite::CONTEXT_SMART_LEARNING,
        ]));

        $this->startExamSession($tenant, $learner, $course, $blockedQuestion, $blockedRevision, now());

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/learn?mode=favorites");

        $response->assertOk();
        $response->assertViewIs('learning.empty');
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

    private function findLessonWithAtLeastTwoQuestions($course): VideoLesson
    {
        return $this->onAdmin(function () use ($course) {
            foreach ($course->videoModules()->with('lessons.questions')->get() as $module) {
                foreach ($module->lessons as $lesson) {
                    if ($lesson->questions->count() >= 2) {
                        return $lesson;
                    }
                }
            }

            throw new \RuntimeException('Kein Video-Kapitel mit mindestens 2 Fragen gefunden.');
        });
    }

    /**
     * Bug-Review X10 (Video-Quiz zeigt Fragen aus laufender Prüfung,
     * Absenden schlägt fehl): resolveQuestions() bot eine Frage, die Teil
     * dieser laufenden Session ist, bisher trotzdem aktiv im Video-Quiz an
     * -- answer() lehnte die anschließend eingereichte Antwort dann mit
     * 409 ab (siehe Test oben), statt von vornherein eine andere Frage
     * auszuspielen.
     */
    public function test_video_quiz_leaves_a_question_from_a_genuinely_running_exam_out_of_the_selection(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $lesson = $this->findLessonWithAtLeastTwoQuestions($course);
        $blockedQuestion = $this->onAdmin(fn () => $lesson->questions->first());
        $blockedRevision = $this->onAdmin(fn () => $blockedQuestion->publishedRevision()->load('answers'));
        $this->startExamSession($tenant, $learner, $course, $blockedQuestion, $blockedRevision, now());

        $response = $this->actingAsInTenant($learner, $tenant)
            ->get("/courses/{$course->id}/video/{$lesson->id}/questions/1");

        $response->assertOk();
        $response->assertViewHas('revision', fn ($shown) => $shown->question_id !== $blockedQuestion->id);
    }

    /**
     * Bug-Review X10 (Folgefehler): resolveQuestions() schließt eine
     * blockierte Frage jetzt bereits aus der Auswahl aus (siehe
     * test_video_quiz_leaves_a_question_from_a_genuinely_running_exam_out_of_the_selection()
     * oben) -- ein Request für genau diese (nun nicht mehr existierende)
     * Position liefert deshalb 404 statt des früheren 409 aus answer()'s
     * explizitem Sicherheitsnetz, das für eine so gefilterte Liste nicht
     * mehr erreichbar ist. Die eigentlich geschützte Eigenschaft (es wird
     * nie ein Attempt für die blockierte Frage gespeichert) bleibt
     * unverändert bestehen.
     */
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
            ['revision_id' => $revision->id, 'answer_id' => $answer->id]
        );

        $response->assertStatus(404);
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
            ['revision_id' => $revision->id, 'answer_id' => $answer->id]
        );

        $response->assertOk();
        $this->assertSame('evaluated', $this->onAdmin(fn () => $session->fresh()->status), 'Eine abgelaufene Session muss nebenbei ausgewertet werden, statt weiterhin als blockierend zu gelten.');
    }
}
