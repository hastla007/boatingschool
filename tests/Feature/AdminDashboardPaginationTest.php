<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review R4-4: Teilnehmer-, Anmeldungen- und Coupon-Liste auf /admin
 * paginierten bisher ohne eigenen pageName -- Laravels Default dafür ist
 * bei jedem Paginator derselbe Query-String-Parameter "page". Da alle drei
 * Listen auf EINER Seite liegen (nur per Tab client-seitig umgeschaltet),
 * blätterte "nächste Seite" bei einer Liste automatisch auch die anderen
 * um, weil sie denselben Parameter auswerteten.
 */
class AdminDashboardPaginationTest extends TestCase
{
    use InteractsWithTenants;

    public function test_paginating_the_participants_list_does_not_use_the_generic_shared_page_parameter(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        // 25 pro Seite (siehe AdminDashboardController) -- der 26. Teilnehmer
        // erscheint nur auf Seite 2.
        for ($i = 1; $i <= 26; $i++) {
            $this->createTenantUser($tenant, 'learner', ['display_name' => 'Teilnehmer Nr '.$i]);
        }

        // Das alte, geteilte "page"-Argument darf die Liste nicht mehr
        // beeinflussen -- ohne eigenen pageName hätte das vorher Seite 2
        // gezeigt.
        $response = $this->actingAsInTenant($owner, $tenant)->get('/admin?page=2');
        $response->assertOk();
        $response->assertDontSee('Teilnehmer Nr 26');

        // Der eigene, distinkte Parameter blättert die Liste tatsächlich um.
        $response = $this->actingAsInTenant($owner, $tenant)->get('/admin?participants_page=2');
        $response->assertOk();
        $response->assertSee('Teilnehmer Nr 26');

        $this->assertStringContainsString('participants_page=', $response->getContent());
    }
}
