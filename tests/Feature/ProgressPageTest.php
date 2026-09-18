<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Die Fortschrittsseite ist kursgebunden: jeder gebuchte Kurs hat seine
 * eigene Fortschrittsseite statt einer gemeinsamen "/progress"-Übersicht
 * über alle Kurse hinweg.
 */
class ProgressPageTest extends TestCase
{
    use InteractsWithTenants;

    public function test_progress_page_requires_entitlement_for_that_course(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/progress");

        $response->assertForbidden();
    }

    public function test_progress_stats_are_isolated_per_course(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $courseA = $this->existingCourse('SRC');
        $courseB = $this->existingCourse('UBI');
        $this->grantEntitlement($tenant, $learner, $courseA);
        $this->grantEntitlement($tenant, $learner, $courseB);

        $moduleA = $this->existingModule('SRC');
        [, $revisionA] = $this->anyPublishedQuestionIn($moduleA);
        $correctA = $revisionA->answers->firstWhere('is_correct', true);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$courseA->id}/learn/attempts", [
            'revision_id' => $revisionA->id,
            'answer_id' => $correctA->id,
            'mode' => 'smarttrainer',
        ])->assertOk();

        $progressA = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$courseA->id}/progress");
        $progressB = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$courseB->id}/progress");

        $progressA->assertOk();
        $progressB->assertOk();

        // Der Versuch gehört nur zu Kurs A -- Kurs B darf davon nichts sehen.
        $progressA->assertViewHas('answered', 1);
        $progressB->assertViewHas('answered', 0);
    }

    public function test_global_progress_route_no_longer_exists_in_favour_of_a_per_course_page(): void
    {
        $this->assertFalse(Route::has('progress.index'));
        $this->assertTrue(Route::has('progress.show'));
    }

    public function test_the_course_overview_links_kursfortschritt_to_that_courses_progress_page(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee(route('progress.show', $course));
    }

    public function test_the_kurse_navigation_lists_all_entitled_courses_in_a_dropdown(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $courseA = $this->existingCourse('SRC');
        $courseB = $this->existingCourse('UBI');
        $this->grantEntitlement($tenant, $learner, $courseA);
        $this->grantEntitlement($tenant, $learner, $courseB);

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertSee($courseA->name);
        $response->assertSee($courseB->name);
        $response->assertSee(route('courses.show', $courseA));
        $response->assertSee(route('courses.show', $courseB));
    }
}
