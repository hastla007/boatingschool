<?php

namespace Tests\Feature;

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

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

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
        $response->assertSee('Fortschritt & Statistiken');
        $response->assertSee('Favoriten & Fehler');
        $response->assertSee(route('progress.show', $course));
        $response->assertSee(route('favorites.smart-learning', $course));
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
}
