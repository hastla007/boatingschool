<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Fehlende H1-Überschriften): sowohl der Lernenden-Layout
 * (layouts/app.blade.php) als auch der Superadmin-Layout (layouts/
 * superadmin.blade.php) geben den header-Slot nur unverändert durch --
 * ob eine Seite ein echtes h1 hat, hängt allein davon ab, was die
 * einzelne Seite in ihren eigenen header-Slot schreibt. Fast überall
 * stand dort bisher ein <h2>, ohne dass irgendwo ein <h1> existierte.
 * Dieser Test prüft stellvertretend eine Auswahl der betroffenen Seiten,
 * analog zu FormFieldLabelAssociationTest (U5) -- nicht jede einzelne
 * der betroffenen gut 50 Dateien.
 */
class PageHasAccessibleH1HeadingTest extends TestCase
{
    use InteractsWithTenants;

    public function test_superadmin_dashboard_has_an_h1(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->get('/superadmin');

        $response->assertOk();
        $response->assertSee('<h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Superadmin-Dashboard</h1>', false);
    }

    public function test_superadmin_tenants_index_has_an_h1(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->get('/superadmin/tenants');

        $response->assertOk();
        $response->assertSee('<h1', false);
        $response->assertDontSee('<h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">', false);
    }

    public function test_superadmin_user_show_has_an_h1(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->get("/superadmin/users/{$superadmin->id}");

        $response->assertOk();
        $response->assertSee('<h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">'.$superadmin->name.'</h1>', false);
    }

    public function test_the_courses_index_page_has_an_h1(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/courses');

        $response->assertOk();
        $response->assertSee('<h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Deine Kurse</h1>', false);
    }

    public function test_the_progress_page_has_an_h1(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('progress.show', $course));

        $response->assertOk();
        $response->assertSee('<h1', false);
    }

    /**
     * Bug-Review (Fehlende H1-Überschriften): profile/edit.blade.php hat
     * neben dem Seitentitel (header-Slot, jetzt h1) zwei weitere, bereits
     * korrekte Zwischenüberschriften im Fließtext ("Deine Buchungen",
     * "Deine Daten") -- diese müssen h2 bleiben, nicht Teil eines
     * pauschalen h2->h1-Austauschs werden (sonst zwei h1 pro Seite).
     */
    public function test_the_profile_page_has_exactly_one_h1_and_keeps_its_other_headings_as_h2(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/profile');

        $response->assertOk();
        $response->assertSee('<h1 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">', false);
        $response->assertSee('<h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Deine Buchungen</h2>', false);
        $response->assertSee('<h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Deine Daten</h2>', false);
    }
}
