<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Zurück-Pfeile ohne Beschriftung): mehrere Lernenden-Seiten
 * zeigen oben links einen "Zurück"-Pfeil, der nur aus einem Icon besteht
 * (<x-icon name="chevron-right" class="... rotate-180" />) ohne jeden
 * Text -- ohne aria-label hatte dieser Link für Screenreader keinen
 * erkennbaren Namen (siehe auch Bug-Review Oberfläche U7 für denselben
 * Ansatz bei anderen Icon-only-Elementen). Dieser Test prüft
 * stellvertretend eine Auswahl der betroffenen Seiten, analog zu
 * FormFieldLabelAssociationTest (U5) -- nicht jede einzelne Seite.
 */
class BackArrowAccessibleLabelTest extends TestCase
{
    use InteractsWithTenants;

    public function test_the_exam_papers_overview_back_link_has_an_accessible_label(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('FKN');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('exam.intro', $course));

        $response->assertOk();
        $response->assertSee('aria-label="Zurück zum Kurs"', false);
    }

    public function test_the_progress_page_back_link_has_an_accessible_label(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('progress.show', $course));

        $response->assertOk();
        $response->assertSee('aria-label="Zurück zum Kurs"', false);
    }

    public function test_the_favorites_page_back_link_has_an_accessible_label(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/favorites/smart-learning");

        $response->assertOk();
        $response->assertSee('aria-label="Zurück zum Kurs"', false);
    }

    public function test_the_learning_overview_back_link_has_an_accessible_label(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('learning.overview', $course));

        $response->assertOk();
        $response->assertSee('aria-label="Zurück zum Kurs"', false);
    }
}
