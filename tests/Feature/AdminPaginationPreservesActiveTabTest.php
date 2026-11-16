<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review BB15: Admin-Pagination verliert aktiven Reiter beim
 * Seitenwechsel. Als die Reiter noch rein clientseitig umgeschaltet wurden,
 * enthielten die serverseitig gerenderten Pagination-Links nie den per
 * Reiterwechsel gesetzten tab-Parameter (damals per JavaScript
 * nachgetragen). Jetzt ist jeder Reiter ein eigener Aufruf (?tab=…), und
 * jede Liste hängt ihren Reiter selbst an die Seitenlinks an
 * (AdminDashboardController: withQueryString() plus tab) -- „nächste
 * Seite“ bleibt damit im Reiter, auch ohne JavaScript.
 */
class AdminPaginationPreservesActiveTabTest extends TestCase
{
    use InteractsWithTenants;

    public function test_every_paginated_tab_keeps_its_tab_on_the_page_links(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $lists = [
            'participants' => ['participants', 'participants_page'],
            'praxis-anmeldungen' => ['praxisFormSubmissions', 'anmeldung_page'],
            'willkommensbox' => ['willkommensboxOrders', 'wb_page'],
            'coupons' => ['coupons', 'coupon_page'],
        ];
        foreach ($lists as $tab => [$variable, $pageName]) {
            $response = $this->actingAsInTenant($owner, $tenant)->get("/admin?tab={$tab}")->assertOk();
            $url = $response->viewData($variable)->url(2);
            $this->assertStringContainsString("tab={$tab}", $url, $variable);
            $this->assertStringContainsString("{$pageName}=2", $url, $variable);
        }
    }

    public function test_the_rendered_page_links_of_the_participants_list_stay_in_the_tab(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        // 25 je Seite: der 26. Teilnehmer erzeugt eine zweite Seite.
        for ($i = 1; $i <= 26; $i++) {
            $this->createTenantUser($tenant, 'learner');
        }

        $response = $this->actingAsInTenant($owner, $tenant)->get('/admin?tab=participants')->assertOk();

        $response->assertSee('tab=participants&amp;participants_page=2', false);
        // Kein JavaScript mehr nötig, das die Links nachträglich umschreibt.
        $response->assertDontSee('syncPaginationLinksTab', false);
    }
}
