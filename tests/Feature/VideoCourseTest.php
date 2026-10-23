<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\ExamSessionQuestion;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VideoLesson;
use App\Models\VideoLessonStep;
use App\Models\VideoModule;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class VideoCourseTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    private array $createdQuestionIds = [];

    protected function tearDown(): void
    {
        // Tenant zuerst (kaskadiert entitlement/video_progress/attempt weg),
        // dann der isolierte Test-Kurs (kaskadiert video_module/video_lesson/
        // video_lesson_question weg), zuletzt die isolierten Testfragen.
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }

            foreach ($this->createdQuestionIds as $id) {
                ContentAnswer::whereIn('revision_id', ContentQuestionRevision::where('question_id', $id)->pluck('id'))->delete();
                ContentQuestionRevision::where('question_id', $id)->delete();
                ContentQuestion::where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    public function test_learner_without_entitlement_cannot_view_video_course(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedVideoCourse();

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video");

        $response->assertForbidden();
    }

    public function test_completing_a_lesson_advances_to_the_next_one_and_tracks_progress(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $firstLesson, $secondLesson] = $this->makeIsolatedVideoCourse();
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->post(
            "/courses/{$course->id}/video/{$firstLesson->id}/complete"
        );

        $response->assertRedirect(route('video.show', ['course' => $course, 'lesson' => $secondLesson]));

        $this->asTenant($tenant, function () use ($tenant, $learner, $firstLesson) {
            $this->assertDatabaseHas('video_progress', [
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'video_lesson_id' => $firstLesson->id,
                'completed' => true,
            ]);
        });

        $page = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video");
        $page->assertOk();
        $page->assertSee('1 / 2 Lektionen', false);
    }

    public function test_lesson_step_gallery_is_shown_when_present(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $firstLesson] = $this->makeIsolatedVideoCourse();
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(function () use ($firstLesson) {
            VideoLessonStep::create(['video_lesson_id' => $firstLesson->id, 'title' => 'Schritt eins', 'sort_order' => 1]);
            VideoLessonStep::create(['video_lesson_id' => $firstLesson->id, 'title' => 'Schritt zwei', 'sort_order' => 2]);
        });

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video/{$firstLesson->id}");

        $response->assertOk();
        $response->assertSee('Schritt eins');
        $response->assertSee('Schritt zwei');
    }

    public function test_kapitel_query_param_scopes_the_video_course_to_a_single_module(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $moduleA, $moduleB] = $this->makeIsolatedVideoCourseWithTwoModules();
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video?kapitel={$moduleB->id}");

        $response->assertOk();
        $response->assertSee('Kapitel B Lektion 1');
        $response->assertDontSee('Kapitel A Lektion 1');
        $response->assertSee('0 / 1 Lektionen', false);
    }

    public function test_the_quiz_button_is_hidden_when_a_lesson_has_no_questions_attached(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $firstLesson] = $this->makeIsolatedVideoCourse();
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video/{$firstLesson->id}");

        $response->assertOk();
        $response->assertDontSee('Zu den Fragen');
    }

    public function test_a_learner_can_answer_a_video_quiz_question_and_the_attempt_is_recorded(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $firstLesson] = $this->makeIsolatedVideoCourse();
        $this->grantEntitlement($tenant, $learner, $course);
        [$question, $revision, $correctAnswer] = $this->attachIsolatedQuestion($firstLesson);

        $lessonPage = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video/{$firstLesson->id}");
        $lessonPage->assertOk();
        $lessonPage->assertSee('Zu den Fragen (1)');
        // Solange Fragen hinterlegt sind, ersetzt "Zu den Fragen" den
        // direkten "Nächstes Video"-Button auf der Videoseite selbst.
        $lessonPage->assertDontSee('Nächstes Video');

        $quizPage = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video/{$firstLesson->id}/questions/1");
        $quizPage->assertOk();
        $quizPage->assertSee($revision->question_text);

        // Bug-Review N19: answer() leitet nach dem Speichern per Redirect auf
        // show() weiter (Post/Redirect/Get) statt die Rückmeldung direkt als
        // POST-Antwort zu rendern -- followingRedirects() folgt dem hier.
        $answerResponse = $this->actingAsInTenant($learner, $tenant)->followingRedirects()->post(
            "/courses/{$course->id}/video/{$firstLesson->id}/questions/1",
            ['answer_id' => $correctAnswer->id]
        );
        $answerResponse->assertOk();
        $answerResponse->assertSee('Richtig!');
        // Letzte (und einzige) Frage beantwortet -> führt jetzt zur
        // Auswertungsseite statt direkt zum nächsten Video.
        $answerResponse->assertSee('Auswertung anzeigen');

        $this->asTenant($tenant, function () use ($tenant, $learner, $question, $course) {
            $this->assertDatabaseHas('attempt', [
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'question_id' => $question->id,
                'course_id' => $course->id,
                'context' => 'video_quiz',
                'correct' => true,
            ]);
        });

        $resultPage = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video/{$firstLesson->id}/questions/result");
        $resultPage->assertOk();
        $resultPage->assertSee('Du hast 1 von 1 Fragen richtig beantwortet!');
        $resultPage->assertSee($revision->question_text);
        // Alles richtig -> "Nur falsche Fragen erneut versuchen" ist nicht anklickbar.
        $resultPage->assertDontSee(route('video.quiz.show', ['course' => $course, 'lesson' => $firstLesson, 'position' => 1, 'questions' => $question->id]), false);

        $completeResponse = $this->actingAsInTenant($learner, $tenant)->post(
            "/courses/{$course->id}/video/{$firstLesson->id}/complete"
        );
        $completeResponse->assertRedirect();
        $this->asTenant($tenant, function () use ($tenant, $learner, $firstLesson) {
            $this->assertDatabaseHas('video_progress', [
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'video_lesson_id' => $firstLesson->id,
                'completed' => true,
            ]);
        });
    }

    /**
     * Bug-Review N19: answer() rendertete die Antwort-Rückmeldung bisher
     * direkt als Antwort auf den POST -- ein F5 (Browser: "Formular erneut
     * senden?") sendete die Antwort dann noch einmal und erzeugte einen
     * zweiten Attempt-Datensatz. Nach dem Fix landet der POST per Redirect
     * auf einem GET; ein F5 dort wiederholt nur das (harmlose) GET.
     */
    public function test_submitting_an_answer_redirects_and_a_page_reload_does_not_resend_it(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $firstLesson] = $this->makeIsolatedVideoCourse();
        $this->grantEntitlement($tenant, $learner, $course);
        [$question, , $correctAnswer] = $this->attachIsolatedQuestion($firstLesson);

        $answerResponse = $this->actingAsInTenant($learner, $tenant)->post(
            "/courses/{$course->id}/video/{$firstLesson->id}/questions/1",
            ['answer_id' => $correctAnswer->id]
        );
        $answerResponse->assertRedirect("/courses/{$course->id}/video/{$firstLesson->id}/questions/1");

        $afterRedirect = $this->actingAsInTenant($learner, $tenant)->get(
            "/courses/{$course->id}/video/{$firstLesson->id}/questions/1"
        );
        $afterRedirect->assertOk();
        $afterRedirect->assertSee('Richtig!');

        // F5 auf derselben Seite: die Flash-Rückmeldung ist bereits
        // verbraucht, die Frage erscheint wieder unbeantwortet -- kein
        // erneutes Absenden, kein zweiter Attempt.
        $reload = $this->actingAsInTenant($learner, $tenant)->get(
            "/courses/{$course->id}/video/{$firstLesson->id}/questions/1"
        );
        $reload->assertOk();
        $reload->assertDontSee('Richtig!');

        $this->asTenant($tenant, function () use ($tenant, $learner, $question, $course) {
            $this->assertSame(1, Attempt::where('tenant_id', $tenant->id)
                ->where('user_id', $learner->id)->where('question_id', $question->id)
                ->where('course_id', $course->id)->where('context', 'video_quiz')->count());
        });
    }

    public function test_the_result_page_lets_the_learner_retry_only_the_wrong_questions(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $firstLesson] = $this->makeIsolatedVideoCourse();
        $this->grantEntitlement($tenant, $learner, $course);
        [$question, $revision, $correctAnswer, $wrongAnswer] = $this->attachIsolatedQuestion($firstLesson);

        $this->actingAsInTenant($learner, $tenant)->post(
            "/courses/{$course->id}/video/{$firstLesson->id}/questions/1",
            ['answer_id' => $wrongAnswer->id]
        );

        $resultPage = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video/{$firstLesson->id}/questions/result");
        $resultPage->assertOk();
        $resultPage->assertSee('Du hast 0 von 1 Fragen richtig beantwortet!');
        $retryWrongUrl = route('video.quiz.show', ['course' => $course, 'lesson' => $firstLesson, 'position' => 1, 'questions' => $question->id]);
        $resultPage->assertSee($retryWrongUrl, false);

        $retryPage = $this->actingAsInTenant($learner, $tenant)->get($retryWrongUrl);
        $retryPage->assertOk();
        $retryPage->assertSee($revision->question_text);

        $retryAnswer = $this->actingAsInTenant($learner, $tenant)->followingRedirects()->post(
            "/courses/{$course->id}/video/{$firstLesson->id}/questions/1?questions={$question->id}",
            ['answer_id' => $correctAnswer->id]
        );
        $retryAnswer->assertOk();
        $retryAnswer->assertSee('Richtig!');

        $finalResult = $this->actingAsInTenant($learner, $tenant)->get(
            "/courses/{$course->id}/video/{$firstLesson->id}/questions/result?questions={$question->id}"
        );
        $finalResult->assertOk();
        $finalResult->assertSee('Du hast 1 von 1 Fragen richtig beantwortet!');
    }

    /**
     * Bug-Review N13: viele Fragen aus dem Katalog stecken unverändert auch
     * in amtlichen Prüfungsbögen. Ohne diese Prüfung konnte ein Lerner
     * während einer laufenden Prüfung dieselbe Frage im Video-Quiz
     * aufrufen und sich die dort sofort angezeigte richtige Antwort
     * verraten lassen.
     */
    public function test_a_question_from_a_running_exam_cannot_be_answered_in_the_video_quiz(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $firstLesson] = $this->makeIsolatedVideoCourse();
        $this->grantEntitlement($tenant, $learner, $course);
        [$question, $revision, $correctAnswer] = $this->attachIsolatedQuestion($firstLesson);
        $this->startRunningExamSessionWithQuestion($tenant, $learner, $course, $question, $revision);

        $response = $this->actingAsInTenant($learner, $tenant)->post(
            "/courses/{$course->id}/video/{$firstLesson->id}/questions/1",
            ['answer_id' => $correctAnswer->id]
        );

        $response->assertStatus(409);
        $this->asTenant($tenant, function () use ($tenant, $learner, $question, $course) {
            $this->assertDatabaseMissing('attempt', [
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'question_id' => $question->id,
                'course_id' => $course->id,
                'context' => 'video_quiz',
            ]);
        });
    }

    public function test_a_question_can_be_answered_in_the_video_quiz_once_its_exam_is_evaluated(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $firstLesson] = $this->makeIsolatedVideoCourse();
        $this->grantEntitlement($tenant, $learner, $course);
        [$question, $revision, $correctAnswer] = $this->attachIsolatedQuestion($firstLesson);
        $this->startRunningExamSessionWithQuestion($tenant, $learner, $course, $question, $revision, 'evaluated');

        $response = $this->actingAsInTenant($learner, $tenant)->followingRedirects()->post(
            "/courses/{$course->id}/video/{$firstLesson->id}/questions/1",
            ['answer_id' => $correctAnswer->id]
        );

        $response->assertOk();
        $response->assertSee('Richtig!');
    }

    private function startRunningExamSessionWithQuestion(
        Tenant $tenant,
        User $learner,
        CourseDefinition $course,
        ContentQuestion $question,
        ContentQuestionRevision $revision,
        string $status = 'running'
    ): void {
        $this->onAdmin(function () use ($tenant, $learner, $course, $question, $revision, $status) {
            $ruleSet = ExamRuleSet::create([
                'course_id' => $course->id,
                'version' => 'test-'.uniqid(),
                'time_limit_seconds' => 3600,
                'passing_rule' => ['min_correct_ratio' => 0.75],
                'status' => 'published',
            ]);

            $session = ExamSession::create([
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'course_id' => $course->id,
                'rule_set_id' => $ruleSet->id,
                'status' => $status,
                'started_at' => now(),
            ]);

            ExamSessionQuestion::create([
                'tenant_id' => $tenant->id,
                'exam_session_id' => $session->id,
                'question_id' => $question->id,
                'revision_id' => $revision->id,
                'position' => 1,
            ]);
        });
    }

    public function test_the_video_quiz_requires_an_active_entitlement(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $firstLesson] = $this->makeIsolatedVideoCourse();
        $this->attachIsolatedQuestion($firstLesson);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video/{$firstLesson->id}/questions/1");

        $response->assertForbidden();
    }

    public function test_superadmin_can_attach_and_detach_a_question_to_a_video_lesson(): void
    {
        $superadmin = $this->createSuperAdmin();
        [, $firstLesson] = $this->makeIsolatedVideoCourse();
        [$question, $revision] = $this->attachIsolatedQuestion($firstLesson);
        $this->onAdmin(fn () => $firstLesson->questions()->detach($question->id));

        $searchResponse = $this->actingAs($superadmin)->get(
            '/superadmin/video-lessons/'.$firstLesson->id.'?q='.urlencode(substr($revision->question_text, 0, 20))
        );
        $searchResponse->assertOk();
        $searchResponse->assertSee($revision->question_text);

        $attachResponse = $this->actingAs($superadmin)->post("/superadmin/video-lessons/{$firstLesson->id}/questions", [
            'question_id' => $question->id,
        ]);
        $attachResponse->assertRedirect();
        $this->assertDatabaseHas('video_lesson_question', ['video_lesson_id' => $firstLesson->id, 'question_id' => $question->id]);

        $showResponse = $this->actingAs($superadmin)->get('/superadmin/video-lessons/'.$firstLesson->id);
        $showResponse->assertOk();
        $showResponse->assertSee($revision->question_text);

        $detachResponse = $this->actingAs($superadmin)->delete("/superadmin/video-lessons/{$firstLesson->id}/questions/{$question->id}");
        $detachResponse->assertRedirect();
        $this->assertDatabaseMissing('video_lesson_question', ['video_lesson_id' => $firstLesson->id, 'question_id' => $question->id]);
    }

    /** @return array{0: ContentQuestion, 1: ContentQuestionRevision, 2: ContentAnswer, 3: ContentAnswer} */
    private function attachIsolatedQuestion(VideoLesson $lesson): array
    {
        return $this->onAdmin(function () use ($lesson) {
            $question = ContentQuestion::create([
                'content_id' => 'TEST-VQ-Q-'.uniqid(),
                'language' => 'de-DE',
                'active' => true,
            ]);
            $this->createdQuestionIds[] = $question->id;

            $revision = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 1,
                'question_type' => 'single_choice',
                'question_text' => 'E2E Video-Quiz: Was bedeutet Steuerbord?',
                'editorial_status' => 'published',
                'rights_status' => 'licensed',
            ]);

            $correctAnswer = ContentAnswer::create([
                'revision_id' => $revision->id, 'answer_key' => 'A', 'answer_text' => 'Rechte Schiffsseite', 'is_correct' => true, 'sort_order' => 1,
            ]);
            $wrongAnswer = ContentAnswer::create([
                'revision_id' => $revision->id, 'answer_key' => 'B', 'answer_text' => 'Linke Schiffsseite', 'is_correct' => false, 'sort_order' => 2,
            ]);

            $lesson->questions()->attach($question->id, ['sort_order' => 1]);

            return [$question, $revision, $correctAnswer, $wrongAnswer];
        });
    }

    /** @return array{0: CourseDefinition, 1: VideoLesson, 2: VideoLesson} */
    private function makeIsolatedVideoCourse(): array
    {
        return $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-VIDEO-'.uniqid(),
                'name' => 'Test Videokurs',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $module = VideoModule::create(['course_id' => $course->id, 'title' => 'Test-Kapitel', 'sort_order' => 1]);
            $first = VideoLesson::create(['video_module_id' => $module->id, 'title' => 'Lektion 1', 'video_url' => 'https://example.test/1.mp4', 'sort_order' => 1]);
            $second = VideoLesson::create(['video_module_id' => $module->id, 'title' => 'Lektion 2', 'video_url' => 'https://example.test/2.mp4', 'sort_order' => 2]);

            return [$course, $first, $second];
        });
    }

    /** @return array{0: CourseDefinition, 1: VideoModule, 2: VideoModule} */
    private function makeIsolatedVideoCourseWithTwoModules(): array
    {
        return $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-VIDEO-'.uniqid(),
                'name' => 'Test Videokurs',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $moduleA = VideoModule::create(['course_id' => $course->id, 'title' => 'Kapitel A', 'sort_order' => 1]);
            VideoLesson::create(['video_module_id' => $moduleA->id, 'title' => 'Kapitel A Lektion 1', 'video_url' => 'https://example.test/a1.mp4', 'sort_order' => 1]);

            $moduleB = VideoModule::create(['course_id' => $course->id, 'title' => 'Kapitel B', 'sort_order' => 2]);
            VideoLesson::create(['video_module_id' => $moduleB->id, 'title' => 'Kapitel B Lektion 1', 'video_url' => 'https://example.test/b1.mp4', 'sort_order' => 1]);

            return [$course, $moduleA, $moduleB];
        });
    }
}
