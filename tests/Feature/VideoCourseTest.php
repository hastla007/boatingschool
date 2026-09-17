<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoModule;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class VideoCourseTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    protected function tearDown(): void
    {
        // Tenant zuerst (kaskadiert entitlement/video_progress weg), dann
        // den isolierten Test-Kurs (kaskadiert video_module/video_lesson weg).
        $this->cleanUpCreatedTenants();

        $this->onAdmin(function () {
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
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

        $this->assertDatabaseHas('video_progress', [
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'video_lesson_id' => $firstLesson->id,
            'completed' => true,
        ]);

        $page = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video");
        $page->assertOk();
        $page->assertSee('1 / 2 Lektionen', false);
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
}
