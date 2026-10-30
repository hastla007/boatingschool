<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Willkommensbox-Tab scrollt auf dem Handy seitlich): die
 * Tab-Leiste in admin/dashboard.blade.php scrollt bei vielen Tabs bereits
 * korrekt horizontal (overflow-x-auto), startete aber immer bei
 * Scroll-Position 0. Ein Aufruf mit ?tab=willkommensbox (z. B. über einen
 * direkten Link) oder jeder andere Tab, der nicht unter die ersten
 * sichtbaren Buttons passt, landete dadurch auf einem Tab-Button, der bei
 * 360px bereits außerhalb des sichtbaren Bereichs der Tab-Leiste lag --
 * sichtbar war z. B. "Dashboard | Teilnehmer | Praxis-Anmeldun…", ohne
 * erkennbar zu machen, dass tatsächlich "Willkommensbox" aktiv ist, und
 * ohne dass die Tab-Leiste von selbst dorthin scrollte. data-tab an jedem
 * Button plus scrollIntoView() beim Laden UND bei jedem Tab-Wechsel bringt
 * den jeweils aktiven Button jetzt zuverlässig in den sichtbaren Bereich
 * (per Playwright an der echten, laufenden Seite bei 360px verifiziert:
 * die Tab-Leiste scrollte beim Aufruf mit ?tab=willkommensbox von selbst,
 * der "Willkommensbox"-Button wurde dadurch vollständig sichtbar).
 */
class AdminTabBarScrollsActiveTabIntoViewTest extends TestCase
{
    use InteractsWithTenants;

    public function test_every_tab_button_carries_a_data_tab_attribute_matching_its_tab_value(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->get('/admin');

        $response->assertOk();
        foreach (['dashboard', 'participants', 'praxis-anmeldungen', 'willkommensbox', 'coupons', 'courses', 'orte', 'branding', 'addons', 'course-promo-banners'] as $tab) {
            $response->assertSee("data-tab=\"{$tab}\"", false);
            $response->assertSee("data-tab=\"{$tab}\" @click=\"tab = '{$tab}'\"", false);
        }
    }

    public function test_the_active_tab_button_is_scrolled_into_view_on_load_and_on_every_tab_change(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->get('/admin');

        $response->assertOk();
        $response->assertSee("\$nextTick(() => \$el.querySelector(`[data-tab='\${tab}']`)?.scrollIntoView({ inline: 'nearest', block: 'nearest' }));", false);
        $response->assertSee("\$nextTick(() => \$el.querySelector(`[data-tab='\${value}']`)?.scrollIntoView({ inline: 'nearest', block: 'nearest' }));", false);
    }
}
