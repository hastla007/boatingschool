<?php

namespace Tests\Feature;

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoModule;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Review-Runde 4: Video-Lektionen bleiben nicht offen, wenn das Quiz
 * keine ausspielbaren Fragen hat; zu große Positionen sind 404 statt 500.
 */
class VideoQuizPlayableQuestionsTest extends TestCase
{
    use InteractsWithTenants;

    private array $courseIds = [];

    private array $questionIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        $this->onAdmin(function () {
            foreach ($this->courseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
            foreach ($this->questionIds as $id) {
                ContentAnswer::whereIn('revision_id', ContentQuestionRevision::where('question_id', $id)->pluck('id'))->delete();
                ContentQuestionRevision::where('question_id', $id)->delete();
                ContentQuestion::where('id', $id)->delete();
            }
        });
        parent::tearDown();
    }

    public function test_a_lesson_whose_only_quiz_question_is_deactivated_can_be_completed(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $lesson] = $this->course();
        $this->grantEntitlement($tenant, $learner, $course);
        [$question] = $this->question($lesson, 1);
        $this->onAdmin(fn () => $question->update(['active' => false]));

        $page = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video/{$lesson->id}");
        $page->assertOk();
        $page->assertDontSee('Zu den Fragen');
        $page->assertSee(route('video.complete', ['course' => $course, 'lesson' => $lesson]), false);
    }

    public function test_a_question_without_published_revision_is_left_out_of_the_quiz(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $lesson] = $this->course();
        $this->grantEntitlement($tenant, $learner, $course);
        [$q1, $r1, $a1] = $this->question($lesson, 1);
        [$q2, $r2] = $this->question($lesson, 2);
        $this->onAdmin(fn () => $r2->update(['editorial_status' => 'draft']));

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video/{$lesson->id}")->assertSee('Zu den Fragen (1)');
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video/{$lesson->id}/questions/1")->assertOk()->assertSee('1/1');
    }

    public function test_an_overflowing_quiz_position_is_not_found(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $lesson] = $this->course();
        $this->grantEntitlement($tenant, $learner, $course);
        $this->question($lesson, 1);
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video/{$lesson->id}/questions/99999999999999999999")->assertNotFound();
    }

    private function course(): array
    {
        return $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create(['tenant_id' => null, 'code' => 'R4-VID-'.uniqid(), 'name' => 'R4 Video', 'course_type' => 'full', 'status' => 'published']);
            $this->courseIds[] = $course->id;
            $module = VideoModule::create(['course_id' => $course->id, 'title' => 'Kapitel', 'sort_order' => 1]);
            $lesson = VideoLesson::create(['video_module_id' => $module->id, 'title' => 'L1', 'video_url' => 'https://example.test/1.mp4', 'sort_order' => 1]);

            return [$course, $lesson];
        });
    }

    private function question(VideoLesson $lesson, int $sort): array
    {
        return $this->onAdmin(function () use ($lesson, $sort) {
            $q = ContentQuestion::create(['content_id' => 'R4-VQ-'.uniqid(), 'language' => 'de-DE', 'active' => true]);
            $this->questionIds[] = $q->id;
            $r = ContentQuestionRevision::create(['question_id' => $q->id, 'revision_no' => 1, 'question_type' => 'single_choice', 'question_text' => 'R4 Frage '.$sort, 'editorial_status' => 'published', 'rights_status' => 'licensed']);
            $a = ContentAnswer::create(['revision_id' => $r->id, 'answer_key' => 'A', 'answer_text' => 'Ja', 'is_correct' => true, 'sort_order' => 1]);
            ContentAnswer::create(['revision_id' => $r->id, 'answer_key' => 'B', 'answer_text' => 'Nein', 'is_correct' => false, 'sort_order' => 2]);
            $lesson->questions()->attach($q->id, ['sort_order' => $sort]);

            return [$q, $r, $a];
        });
    }
}
