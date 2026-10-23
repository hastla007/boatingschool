<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\Progress;
use App\Models\VideoProgress;
use App\Support\VideoCourseLessons;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * "Lernen" (Dashboard) zeigt neben dem Fortschritt des zuletzt aktiven
 * Kurses auch Kacheln, die direkt zu dessen Fortschritts- und
 * Favoriten-Seite führen -- Gegenstück zu den gleichnamigen Untermenüs in
 * der Hauptnavigation (siehe navigation.blade.php).
 */
class DashboardTest extends TestCase
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

    public function test_dashboard_links_to_progress_and_favorites_of_the_active_course(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Zum Kurs');
        $response->assertSee('Fortschritt & Statistiken');
        $response->assertSee('Favoriten & Fehler');
        $response->assertSee(route('learning.show', $course));
        $response->assertSee(route('progress.show', $course));
        $response->assertSee(route('favorites.smart-learning', $course));
    }

    public function test_dashboard_shows_tiles_for_every_entitled_course(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $courseA = $this->existingCourse('SRC');
        $courseB = $this->existingCourse('UBI');
        $this->grantEntitlement($tenant, $learner, $courseA);
        $this->grantEntitlement($tenant, $learner, $courseB);

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('learning.show', $courseA));
        $response->assertSee(route('progress.show', $courseA));
        $response->assertSee(route('favorites.smart-learning', $courseA));
        $response->assertSee(route('learning.show', $courseB));
        $response->assertSee(route('progress.show', $courseB));
        $response->assertSee(route('favorites.smart-learning', $courseB));
    }

    public function test_dashboard_without_any_course_shows_no_tiles(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('Fortschritt & Statistiken');
        $response->assertDontSee('Favoriten & Fehler');
    }

    /**
     * Bug-Review N22: der Weiterlernen-Banner baute $course->name bisher
     * ungeprüft in eine Zeichenkette ein und gab sie über {!! !!} (also
     * ungeschützt vor HTML/JS) aus. Kursnamen werden aktuell nur vom
     * Superadmin gepflegt (kein erreichbarer Angriffsweg), aber {{ }}
     * escapt jetzt trotzdem automatisch -- als Verteidigung für den Fall,
     * dass Kursnamen künftig aus weniger vertrauenswürdiger Quelle stammen.
     */
    public function test_the_course_name_in_the_banner_subtitle_is_escaped(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-XSS-'.uniqid(),
                'name' => '<script>alert(1)</script>',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            return $course;
        });
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_banner_offers_the_video_course_while_its_lessons_are_not_yet_all_watched(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('FKN');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Videokurs ansehen');
        $response->assertSee(route('video.index', $course));
        $response->assertDontSee('Smarttrainer wählt die nächste Frage für dich');
    }

    public function test_banner_offers_the_smarttrainer_once_all_video_lessons_are_watched(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('FKN');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(function () use ($tenant, $learner, $course) {
            foreach (VideoCourseLessons::ordered($course) as $lesson) {
                VideoProgress::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $learner->id,
                    'video_lesson_id' => $lesson->id,
                    'completed' => true,
                    'updated_at' => now(),
                ]);
            }
        });

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Weiterlernen');
        $response->assertSee(route('learning.show', $course));
        $response->assertDontSee('Videokurs ansehen');
    }

    public function test_banner_offers_the_exam_once_mastery_is_above_90_percent(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('FKN');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(function () use ($tenant, $learner, $course) {
            foreach (VideoCourseLessons::ordered($course) as $lesson) {
                VideoProgress::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $learner->id,
                    'video_lesson_id' => $lesson->id,
                    'completed' => true,
                    'updated_at' => now(),
                ]);
            }

            $questionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique();
            foreach ($questionIds as $questionId) {
                Progress::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $learner->id,
                    'question_id' => $questionId,
                    'attempt_count' => 3,
                    'correct_count' => 3,
                    'incorrect_count' => 0,
                    'current_streak' => 3,
                    'mastery_score' => 1,
                    'learning_state' => 'gefestigt',
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                    'last_correct_at' => now(),
                ]);
            }
        });

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Prüfungsaufgaben');
        $response->assertSee(route('exam.intro', $course));
        $response->assertDontSee('Smarttrainer wählt die nächste Frage für dich');
    }
}
