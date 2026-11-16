<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoModule;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Platzhaltervideos (VideoLesson::PLACEHOLDER_URL): Lernende sehen statt des
 * Platzhalterfilms einen Hinweis „Video folgt“, die Lektion lässt sich nicht
 * abschließen und zählt nirgends zum Videofortschritt. Superadmins sehen
 * den Platzhalter weiterhin, mit Hinweis.
 */
class VideoPlaceholderTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->whereIn('id', $this->createdCourseIds)->delete());
        parent::tearDown();
    }

    /** @return array{0: CourseDefinition, 1: VideoLesson, 2: VideoLesson} Kurs, Platzhalter (zuerst), echtes Video */
    private function courseWithPlaceholderFirst(): array
    {
        return $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null, 'code' => 'TEST-VPH-'.uniqid(), 'name' => 'Platzhalterkurs', 'course_type' => 'full', 'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;
            $module = VideoModule::create(['course_id' => $course->id, 'title' => 'Kapitel 1', 'sort_order' => 1]);
            $placeholder = VideoLesson::create(['video_module_id' => $module->id, 'title' => 'Ankern (folgt)', 'video_url' => VideoLesson::PLACEHOLDER_URL, 'sort_order' => 1]);
            $real = VideoLesson::create(['video_module_id' => $module->id, 'title' => 'Anlegen', 'video_url' => 'https://videos.example.test/anlegen.mp4', 'sort_order' => 2]);

            return [$course, $placeholder, $real];
        });
    }

    public function test_learners_see_a_neutral_card_instead_of_the_placeholder_film(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant);
        [$course, $placeholder] = $this->courseWithPlaceholderFirst();
        $this->grantEntitlement($tenant, $learner, $course);

        $page = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video/{$placeholder->id}")->assertOk();

        $page->assertSee('Video folgt');
        $page->assertSee('data-video-placeholder', false);
        $page->assertDontSee(VideoLesson::PLACEHOLDER_URL, false);
        $page->assertDontSee('<video', false);
        // Statt „abschließen“ geht es einfach weiter.
        $page->assertSee('Weiter zur nächsten Lektion');
        $page->assertDontSee("/video/{$placeholder->id}/complete", false);
    }

    public function test_superadmins_still_see_the_placeholder_with_a_hint(): void
    {
        $tenant = $this->createTestTenant();
        $superadmin = $this->createTenantUser($tenant, 'learner', ['is_superadmin' => true]);
        [$course, $placeholder] = $this->courseWithPlaceholderFirst();
        $this->grantEntitlement($tenant, $superadmin, $course);

        $page = $this->actingAsInTenant($superadmin, $tenant)->get("/courses/{$course->id}/video/{$placeholder->id}")->assertOk();

        $page->assertSee(VideoLesson::PLACEHOLDER_URL, false);
        $page->assertSee('Platzhaltervideo: Lernende sehen hier stattdessen', false);
    }

    public function test_a_placeholder_cannot_be_completed(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant);
        [$course, $placeholder] = $this->courseWithPlaceholderFirst();
        $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAsInTenant($learner, $tenant)
            ->from("/courses/{$course->id}/video/{$placeholder->id}")
            ->post("/courses/{$course->id}/video/{$placeholder->id}/complete")
            ->assertRedirect("/courses/{$course->id}/video/{$placeholder->id}")
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'folgt noch'));

        $this->assertFalse($this->onAdmin(fn () => DB::table('video_progress')->where('user_id', $learner->id)->exists()));
    }

    public function test_placeholders_are_excluded_from_progress_everywhere(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant);
        [$course, $placeholder, $real] = $this->courseWithPlaceholderFirst();
        $this->grantEntitlement($tenant, $learner, $course);
        // Alter Stand aus der Zeit vor dieser Regel: ein „abgeschlossener“ Platzhalter zählt trotzdem nicht.
        $this->onAdmin(fn () => DB::table('video_progress')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'video_lesson_id' => $placeholder->id, 'completed' => true, 'updated_at' => now(),
        ]));

        // Der Einstieg überspringt den Platzhalter.
        $index = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video")->assertOk();
        $index->assertSee('0 / 1 Lektionen', false);
        $index->assertSee('https://videos.example.test/anlegen.mp4', false);

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}")->assertOk()->assertSee('Videofortschritt 0&nbsp;%', false);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/video/{$real->id}/complete")->assertRedirect();

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/video/{$real->id}")->assertOk()->assertSee('1 / 1 Lektionen', false);
        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}")->assertOk()->assertSee('Videofortschritt 100&nbsp;%', false);
    }

    public function test_the_dashboard_does_not_steer_into_a_video_course_that_only_has_placeholders(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant);
        [$course, , $real] = $this->courseWithPlaceholderFirst();
        $this->grantEntitlement($tenant, $learner, $course);

        $summary = fn () => $this->actingAsInTenant($learner, $tenant)->get('/dashboard')->assertOk()
            ->viewData('courses')->firstWhere('course.id', $course->id);

        // Das echte Video ist noch offen: Banner zum Videokurs.
        $this->assertSame('video', $summary()['banner_mode']);

        // Nur noch der Platzhalter offen: weiter zum Smarttrainer.
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/video/{$real->id}/complete")->assertRedirect();
        $this->assertSame('smart', $summary()['banner_mode']);
    }
}
