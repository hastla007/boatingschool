<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoModule;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class SuperadminVideoLessonEditorTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        // Kurs löschen kaskadiert video_module/video_lesson weg.
        $this->onAdmin(function () {
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    public function test_superadmin_can_update_title_url_and_duration(): void
    {
        $superadmin = $this->createSuperAdmin();
        $lesson = $this->makeLesson('Kapitel 1', 'Ankern', VideoLesson::PLACEHOLDER_URL);

        $response = $this->actingAs($superadmin)->patch("/superadmin/video-lessons/{$lesson->id}", [
            'title' => 'Ankern vor Grund',
            'video_url' => 'https://cdn.example.test/ankern.mp4',
            'duration' => '4:30',
        ]);

        $response->assertRedirect("/superadmin/video-lessons/{$lesson->id}");
        $lesson = $this->onAdmin(fn () => $lesson->fresh());
        $this->assertSame('Ankern vor Grund', $lesson->title);
        $this->assertSame('https://cdn.example.test/ankern.mp4', $lesson->video_url);
        $this->assertSame(270, $lesson->duration_seconds);
        $this->assertFalse($lesson->isPlaceholder());
    }

    public function test_duration_in_whole_minutes_and_empty_duration_are_accepted(): void
    {
        $superadmin = $this->createSuperAdmin();
        $lesson = $this->makeLesson('Kapitel 1', 'Ankern', VideoLesson::PLACEHOLDER_URL);

        $this->actingAs($superadmin)->patch("/superadmin/video-lessons/{$lesson->id}", [
            'title' => 'Ankern', 'video_url' => 'https://cdn.example.test/a.mp4', 'duration' => '12',
        ]);
        $this->assertSame(720, $this->onAdmin(fn () => $lesson->fresh()->duration_seconds));

        $this->actingAs($superadmin)->patch("/superadmin/video-lessons/{$lesson->id}", [
            'title' => 'Ankern', 'video_url' => 'https://cdn.example.test/a.mp4', 'duration' => '',
        ]);
        $this->assertNull($this->onAdmin(fn () => $lesson->fresh()->duration_seconds));
    }

    public function test_non_https_and_script_urls_are_rejected(): void
    {
        $superadmin = $this->createSuperAdmin();
        $lesson = $this->makeLesson('Kapitel 1', 'Ankern', VideoLesson::PLACEHOLDER_URL);

        foreach (['http://cdn.example.test/a.mp4', 'javascript:alert(1)', 'data:video/mp4;base64,AAAA', 'keine-url'] as $url) {
            $response = $this->actingAs($superadmin)->patch("/superadmin/video-lessons/{$lesson->id}", [
                'title' => 'Ankern', 'video_url' => $url,
            ]);
            $response->assertSessionHasErrors('video_url');
        }

        $this->assertSame(VideoLesson::PLACEHOLDER_URL, $this->onAdmin(fn () => $lesson->fresh()->video_url));
    }

    public function test_invalid_duration_is_rejected(): void
    {
        $superadmin = $this->createSuperAdmin();
        $lesson = $this->makeLesson('Kapitel 1', 'Ankern', VideoLesson::PLACEHOLDER_URL);

        $response = $this->actingAs($superadmin)->patch("/superadmin/video-lessons/{$lesson->id}", [
            'title' => 'Ankern', 'video_url' => 'https://cdn.example.test/a.mp4', 'duration' => '4:75',
        ]);

        $response->assertSessionHasErrors('duration');
    }

    public function test_url_can_be_applied_to_copies_in_other_courses(): void
    {
        $superadmin = $this->createSuperAdmin();
        $lesson = $this->makeLesson('Kapitel 1', 'Ankern', VideoLesson::PLACEHOLDER_URL);
        $copy = $this->makeLesson('Kapitel 1', 'Ankern', VideoLesson::PLACEHOLDER_URL);
        // Abweichend gepflegte "Kopie" und gleichnamige Lektion in anderem Kapitel bleiben unberührt.
        $customized = $this->makeLesson('Kapitel 1', 'Ankern', 'https://cdn.example.test/eigen.mp4');
        $otherChapter = $this->makeLesson('Kapitel 2', 'Ankern', VideoLesson::PLACEHOLDER_URL);

        $this->actingAs($superadmin)->get("/superadmin/video-lessons/{$lesson->id}")
            ->assertOk()
            ->assertSee('in 1 Kopie dieser Lektion übernehmen');

        $response = $this->actingAs($superadmin)->patch("/superadmin/video-lessons/{$lesson->id}", [
            'title' => 'Ankern',
            'video_url' => 'https://cdn.example.test/ankern.mp4',
            'duration' => '3:05',
            'apply_to_copies' => '1',
        ]);

        $response->assertSessionHas('status', 'Lektion gespeichert, Video auch in 1 Kopie übernommen.');
        $this->onAdmin(function () use ($copy, $customized, $otherChapter) {
            $this->assertSame('https://cdn.example.test/ankern.mp4', $copy->fresh()->video_url);
            $this->assertSame(185, $copy->fresh()->duration_seconds);
            $this->assertSame('https://cdn.example.test/eigen.mp4', $customized->fresh()->video_url);
            $this->assertSame(VideoLesson::PLACEHOLDER_URL, $otherChapter->fresh()->video_url);
        });
    }

    public function test_copies_stay_untouched_without_the_checkbox(): void
    {
        $superadmin = $this->createSuperAdmin();
        $lesson = $this->makeLesson('Kapitel 1', 'Ankern', VideoLesson::PLACEHOLDER_URL);
        $copy = $this->makeLesson('Kapitel 1', 'Ankern', VideoLesson::PLACEHOLDER_URL);

        $this->actingAs($superadmin)->patch("/superadmin/video-lessons/{$lesson->id}", [
            'title' => 'Ankern',
            'video_url' => 'https://cdn.example.test/ankern.mp4',
        ])->assertSessionHas('status', 'Lektion gespeichert.');

        $this->assertSame(VideoLesson::PLACEHOLDER_URL, $this->onAdmin(fn () => $copy->fresh()->video_url));
    }

    public function test_course_page_marks_placeholder_lessons(): void
    {
        $superadmin = $this->createSuperAdmin();
        $lesson = $this->makeLesson('Kapitel 1', 'Ankern', VideoLesson::PLACEHOLDER_URL);

        $this->actingAs($superadmin)->get("/superadmin/courses/{$lesson->module->course_id}")
            ->assertOk()
            ->assertSee('1 von 1 Lektionen mit Platzhaltervideo');
    }

    public function test_tenant_admin_cannot_edit_video_lessons(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $lesson = $this->makeLesson('Kapitel 1', 'Ankern', VideoLesson::PLACEHOLDER_URL);

        $this->actingAs($admin)->patch("/superadmin/video-lessons/{$lesson->id}", [
            'title' => 'Gekapert', 'video_url' => 'https://evil.example.test/a.mp4',
        ])->assertForbidden();

        $this->assertSame('Ankern', $this->onAdmin(fn () => $lesson->fresh()->title));
    }

    private function makeLesson(string $chapterTitle, string $lessonTitle, string $url): VideoLesson
    {
        return $this->onAdmin(function () use ($chapterTitle, $lessonTitle, $url) {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-VEDIT-'.uniqid(),
                'name' => 'Test Videoeditor',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $module = VideoModule::create(['course_id' => $course->id, 'title' => $chapterTitle.' '.$this->uniqueSuffix(), 'sort_order' => 1]);

            return VideoLesson::create(['video_module_id' => $module->id, 'title' => $lessonTitle, 'video_url' => $url, 'sort_order' => 1]);
        });
    }

    /**
     * Kapiteltitel pro Testlauf eindeutig machen, damit die Kopien-Erkennung
     * (gleicher Kapitel- und Lektionstitel, gleiche URL) nicht auf echte,
     * geseedete Lektionen wie "Ankern" in einem SBF-Kurs anspringt.
     */
    private function uniqueSuffix(): string
    {
        return $this->suffix ??= uniqid('e2e-');
    }

    private ?string $suffix = null;
}
