<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review BB15: Admin-Pagination verliert aktiven Reiter beim
 * Seitenwechsel. Der Tab-Wechsel in admin/dashboard.blade.php läuft rein
 * clientseitig über history.replaceState(), ohne eigenen Request -- die
 * Pagination-Links in den Tabs (Teilnehmer, Praxis-Anmeldungen,
 * Willkommensbox, Coupon-Codes) wurden dagegen serverseitig mit dem
 * Query-String des ERSTEN Requests gerendert und enthielten daher nie den
 * per Tab-Wechsel gesetzten tab-Parameter. Ein Klick auf "nächste Seite"
 * landete dadurch immer auf dem Dashboard-Tab, unabhängig vom zuvor
 * gewählten Tab. syncPaginationLinksTab() schreibt den aktuellen
 * tab-Wert bei jedem Tab-Wechsel und beim initialen Laden in jeden
 * Pagination-Link.
 */
class AdminPaginationPreservesActiveTabTest extends TestCase
{
    use InteractsWithTenants;

    public function test_dashboard_syncs_the_active_tab_onto_pagination_links_on_load_and_on_every_tab_change(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->get('/admin');

        $response->assertOk();
        $response->assertSee("\$el.querySelectorAll('a[href*=\'_page=\']').forEach((link) => {", false);
        $response->assertSee("linkUrl.searchParams.set('tab', value);", false);
        $response->assertSee('syncPaginationLinksTab(value);', false);
        $response->assertSee('syncPaginationLinksTab(tab);', false);
    }
}
