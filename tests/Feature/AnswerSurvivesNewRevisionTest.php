<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\Module;
use App\Models\VideoLesson;
use App\Models\VideoModule;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Antwort geht bei neuer Revision verloren): wurde zwischen
 * Anzeige und Abschicken einer Frage eine neue Fassung veröffentlicht, ist
 * die angezeigte Revision "deprecated" -- Smart-Learning (storeAttempt)
 * und Video-Quiz (answer) antworteten darauf bisher mit 404, die Antwort
 * ging verloren. Die angezeigte Fassung wird jetzt angenommen und gegen
 * genau diese bewertet.
 */
class AnswerSurvivesNewRevisionTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    private array $createdModuleIds = [];

    private array $createdQuestionIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenants();

        $this->onAdmin(function () {
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
            foreach ($this->createdModuleIds as $id) {
                Module::where('id', $id)->delete();
            }
            foreach ($this->createdQuestionIds as $id) {
                ContentQuestion::where('id', $id)->delete();
            }
        });

        $this->cleanUpCreatedUsers();

        parent::tearDown();
    }

    public function test_learning_accepts_an_answer_on_a_revision_replaced_after_display(): void
    {
        [$course, $question, $oldRevision, $oldCorrect] = $this->makeCourseWithQuestion();
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->publishNewRevision($question);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", [
            'revision_id' => $oldRevision->id,
            'answer_id' => $oldCorrect->id,
            'mode' => 'smarttrainer',
        ])->assertRedirect();

        $attempt = $this->asTenant($tenant, fn () => Attempt::where('user_id', $learner->id)->sole());
        $this->assertSame($oldRevision->id, $attempt->revision_id);
        $this->assertTrue($attempt->correct);
    }

    public function test_learning_still_rejects_an_old_revision_of_a_deactivated_question(): void
    {
        [$course, $question, $oldRevision, $oldCorrect] = $this->makeCourseWithQuestion();
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->publishNewRevision($question);
        $this->onAdmin(fn () => $question->update(['active' => false]));

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/learn/attempts", [
            'revision_id' => $oldRevision->id,
            'answer_id' => $oldCorrect->id,
            'mode' => 'smarttrainer',
        ])->assertNotFound();
    }

    public function test_video_quiz_accepts_an_answer_on_a_revision_replaced_after_display(): void
    {
        [$course, $question, $oldRevision, $oldCorrect] = $this->makeCourseWithQuestion();
        $lesson = $this->onAdmin(function () use ($course, $question) {
            $videoModule = VideoModule::create(['course_id' => $course->id, 'title' => 'Kapitel', 'sort_order' => 1]);
            $lesson = VideoLesson::create(['video_module_id' => $videoModule->id, 'title' => 'Lektion', 'video_url' => 'https://example.test/1.mp4', 'sort_order' => 1]);
            $lesson->questions()->attach($question->id, ['sort_order' => 1]);

            return $lesson;
        });
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->publishNewRevision($question);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/video/{$lesson->id}/questions/1", [
            'revision_id' => $oldRevision->id,
            'answer_id' => $oldCorrect->id,
        ])->assertRedirect();

        $attempt = $this->asTenant($tenant, fn () => Attempt::where('user_id', $learner->id)->sole());
        $this->assertSame($oldRevision->id, $attempt->revision_id);
        $this->assertTrue($attempt->correct);
    }

    /** @return array{0: CourseDefinition, 1: ContentQuestion, 2: ContentQuestionRevision, 3: ContentAnswer} */
    private function makeCourseWithQuestion(): array
    {
        return $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-REV-'.uniqid(),
                'name' => 'Test Kurs Revision',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $module = Module::create(['code' => 'TEST-REV-MOD-'.uniqid(), 'name' => 'Test Modul Revision', 'version' => '1', 'status' => 'published']);
            $this->createdModuleIds[] = $module->id;
            $course->modules()->attach($module->id);

            $question = ContentQuestion::create(['content_id' => 'TEST-REV-Q-'.uniqid(), 'language' => 'de-DE', 'active' => true]);
            $this->createdQuestionIds[] = $question->id;
            $module->questions()->attach($question->id, ['required' => true]);

            [$revision, $correct] = $this->makeRevision($question, 1, 'published', 'Alte Fassung: Was ist Steuerbord?');

            return [$course, $question, $revision, $correct];
        });
    }

    private function publishNewRevision(ContentQuestion $question): void
    {
        $this->onAdmin(function () use ($question) {
            [$revision] = $this->makeRevision($question, 2, 'review', 'Neue Fassung: Was ist Steuerbord?');
            $revision->publish(resetProgressOnContentChange: false);
        });
    }

    /** @return array{0: ContentQuestionRevision, 1: ContentAnswer} */
    private function makeRevision(ContentQuestion $question, int $no, string $status, string $text): array
    {
        $revision = ContentQuestionRevision::create([
            'question_id' => $question->id,
            'revision_no' => $no,
            'question_type' => 'single_choice',
            'question_text' => $text,
            'editorial_status' => $status,
            'rights_status' => 'licensed',
        ]);
        $correct = ContentAnswer::create(['revision_id' => $revision->id, 'answer_key' => 'A', 'answer_text' => 'Rechts', 'is_correct' => true, 'sort_order' => 1]);
        ContentAnswer::create(['revision_id' => $revision->id, 'answer_key' => 'B', 'answer_text' => 'Links', 'is_correct' => false, 'sort_order' => 2]);

        return [$revision, $correct];
    }
}
