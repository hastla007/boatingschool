<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoModule;
use App\Models\VideoProgress;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Videokurs im Superadmin: Kapitel/Lektionen anlegen, umsortieren, löschen. */
class SuperadminVideoCourseStructureTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        $this->onAdmin(function () {
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
        });
        parent::tearDown();
    }

    private function makeCourse(): CourseDefinition
    {
        return $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null, 'code' => 'TEST-VSTRUCT-'.uniqid(), 'name' => 'Test Videostruktur', 'course_type' => 'full', 'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            return $course;
        });
    }

    private function titles(VideoModule $module): array
    {
        return $this->onAdmin(fn () => $module->lessons()->pluck('title')->all());
    }

    public function test_chapters_and_lessons_are_created_and_reordered(): void
    {
        $superadmin = $this->createSuperAdmin();
        $course = $this->makeCourse();

        $this->actingAs($superadmin)->post("/superadmin/courses/{$course->id}/video-modules", ['title' => 'Kapitel A'])->assertRedirect();
        $this->actingAs($superadmin)->post("/superadmin/courses/{$course->id}/video-modules", ['title' => 'Kapitel B']);
        [$a, $b] = $this->onAdmin(fn () => $course->videoModules()->get()->all());
        $this->assertSame(['Kapitel A', 'Kapitel B'], [$a->title, $b->title]);

        foreach (['Eins', 'Zwei', 'Drei'] as $title) {
            $this->actingAs($superadmin)->post("/superadmin/video-modules/{$a->id}/lessons", ['title' => $title]);
        }
        $this->assertSame(['Eins', 'Zwei', 'Drei'], $this->titles($a));
        $drei = $this->onAdmin(fn () => VideoLesson::where('video_module_id', $a->id)->where('title', 'Drei')->first());
        $this->assertTrue($drei->isPlaceholder(), 'ohne Video zunächst Platzhalter');

        $this->actingAs($superadmin)->post("/superadmin/video-lessons/{$drei->id}/move", ['direction' => 'up']);
        $this->assertSame(['Eins', 'Drei', 'Zwei'], $this->titles($a));
        // Ganz oben: nach oben ändert nichts.
        $eins = $this->onAdmin(fn () => VideoLesson::where('video_module_id', $a->id)->where('title', 'Eins')->first());
        $this->actingAs($superadmin)->post("/superadmin/video-lessons/{$eins->id}/move", ['direction' => 'up']);
        $this->assertSame(['Eins', 'Drei', 'Zwei'], $this->titles($a));

        $this->actingAs($superadmin)->post("/superadmin/video-modules/{$b->id}/move", ['direction' => 'up']);
        $this->assertSame(['Kapitel B', 'Kapitel A'], $this->onAdmin(fn () => $course->videoModules()->pluck('title')->all()));

        $this->actingAs($superadmin)->patch("/superadmin/video-modules/{$b->id}", ['title' => 'Kapitel B neu']);
        $this->actingAs($superadmin)->get("/superadmin/courses/{$course->id}")->assertOk()->assertSee('Kapitel B neu')->assertSee('Lektion anlegen');
    }

    public function test_deleting_lessons_and_chapters(): void
    {
        $superadmin = $this->createSuperAdmin();
        $course = $this->makeCourse();
        [$first, $second, $lesson] = $this->onAdmin(function () use ($course) {
            $first = VideoModule::create(['course_id' => $course->id, 'title' => 'Erstes', 'sort_order' => 1]);
            $second = VideoModule::create(['course_id' => $course->id, 'title' => 'Zweites', 'sort_order' => 2]);
            $lesson = VideoLesson::create(['video_module_id' => $first->id, 'title' => 'Weg damit', 'video_url' => 'https://cdn.example.test/a.mp4', 'sort_order' => 1]);

            return [$first, $second, $lesson];
        });
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => VideoProgress::create(['tenant_id' => $tenant->id, 'user_id' => $learner->id, 'video_lesson_id' => $lesson->id, 'completed' => true, 'updated_at' => now()]));

        // Kapitel mit Lektionen: nicht löschbar.
        $this->actingAs($superadmin)->delete("/superadmin/video-modules/{$first->id}")->assertSessionHasErrors('video_module');

        $this->actingAs($superadmin)->delete("/superadmin/video-lessons/{$lesson->id}")->assertRedirect();
        $this->onAdmin(function () use ($lesson) {
            $this->assertNull(VideoLesson::find($lesson->id));
            $this->assertSame(0, VideoProgress::where('video_lesson_id', $lesson->id)->count());
        });

        $this->actingAs($superadmin)->delete("/superadmin/video-modules/{$first->id}")->assertSessionHasNoErrors();
        // Das letzte Kapitel bleibt (sonst legte der Seeder den Ausgangsstand neu an).
        $this->actingAs($superadmin)->delete("/superadmin/video-modules/{$second->id}")->assertSessionHasErrors('video_module');
        $this->assertSame(['Zweites'], $this->onAdmin(fn () => $course->videoModules()->pluck('title')->all()));
    }

    public function test_learners_cannot_reach_the_editor(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $course = $this->makeCourse();

        $this->actingAsInTenant($admin, $tenant)->post("/superadmin/courses/{$course->id}/video-modules", ['title' => 'X'])->assertForbidden();
    }
}
