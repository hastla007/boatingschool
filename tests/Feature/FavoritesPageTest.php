<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Favoriten sind kursgebunden: jeder gebuchte Kurs hat seine eigene
 * Favoriten-Seite statt einer gemeinsamen "/favorites"-Übersicht über
 * alle Kurse hinweg.
 */
class FavoritesPageTest extends TestCase
{
    use InteractsWithTenants;

    public function test_favorites_page_requires_entitlement_for_that_course(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/favorites");

        $response->assertForbidden();
    }

    public function test_favorites_are_isolated_per_course(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $courseA = $this->existingCourse('SRC');
        $courseB = $this->existingCourse('UBI');
        $this->grantEntitlement($tenant, $learner, $courseA);
        $this->grantEntitlement($tenant, $learner, $courseB);

        $moduleA = $this->existingModule('SRC');
        [$questionA] = $this->anyPublishedQuestionIn($moduleA);

        $this->actingAsInTenant($learner, $tenant)->post("/favorites/{$questionA->id}")->assertRedirect();

        $favoritesA = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$courseA->id}/favorites");
        $favoritesB = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$courseB->id}/favorites");

        $favoritesA->assertOk();
        $favoritesB->assertOk();

        // Der Favorit gehört nur zu Kurs A -- Kurs B darf ihn nicht auflisten.
        $this->assertCount(1, $favoritesA->viewData('favorites'));
        $this->assertCount(0, $favoritesB->viewData('favorites'));
    }

    public function test_the_kurse_navigation_lists_a_favoriten_link_for_each_entitled_course(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('favorites.index', $course));
        $response->assertSee('Favoriten');
    }
}
