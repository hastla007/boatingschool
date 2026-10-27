<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Hoch): Admin und Superadmin waren auf dem Handy nicht
 * bedienbar. Im Superadmin-Layout steckte die gesamte Navigation in einem
 * reinen "hidden md:flex" ohne jeden Ersatz (kein Hamburger-Menü wie im
 * Lerner-Layout) -- unterhalb von 768px Breite war sie komplett
 * unerreichbar. Zusätzlich hatte keine der Listen-Tabellen im Superadmin-
 * und Bootsschul-Admin-Bereich einen horizontal scrollbaren Container --
 * eine zu breite Tabelle wurde dadurch abgeschnitten statt innerhalb ihrer
 * eigenen Karte zu scrollen, Aktionen in den hinteren Spalten (z. B.
 * "Kurs freischalten") waren nicht erreichbar. Da dieses Projekt keine
 * JS-/Browser-Testinfrastruktur hat (kein Jest/Playwright für UI-Verhalten,
 * siehe package.json), prüfen diese Tests die dafür entscheidende
 * HTML-Struktur statt echtes Viewport-Verhalten zu simulieren.
 */
class MobileResponsivenessTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedUsers();

        parent::tearDown();
    }

    public function test_the_superadmin_layout_has_a_mobile_menu_toggle_reaching_every_section(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->get(route('superadmin.dashboard'));
        $response->assertOk();

        // Der Hamburger-Umschalter selbst (Alpine x-data/@click, analog zum
        // bereits bewährten Lerner-Layout, siehe layouts/navigation.blade.php).
        $response->assertSee('x-data="{ open: false }"', false);
        $response->assertSee('@click="open = ! open"', false);

        // Jede der 11 Sektionen muss auch im mobilen Menü (nicht nur im
        // "hidden md:flex"-Desktop-Menü) als Link vorhanden sein.
        foreach ([
            route('superadmin.dashboard'),
            route('superadmin.tenants.index'),
            route('superadmin.users.index'),
            route('superadmin.courses.index'),
            route('superadmin.products.index'),
            route('superadmin.tips.index'),
            route('superadmin.coupons.index'),
            route('superadmin.addon-templates.index'),
            route('superadmin.praxis-form-templates.index'),
            route('superadmin.pruefungsausschuesse.index'),
            route('superadmin.settings.edit'),
        ] as $url) {
            $this->assertGreaterThanOrEqual(
                2,
                substr_count($response->getContent(), 'href="'.$url.'"'),
                "Route {$url} muss sowohl im Desktop- als auch im mobilen Menü verlinkt sein."
            );
        }
    }

    public function test_superadmin_list_tables_scroll_within_their_own_card_instead_of_being_cut_off(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->get(route('superadmin.tenants.index'));
        $response->assertOk();

        $html = $response->getContent();
        $tablePos = strpos($html, '<table');
        $this->assertNotFalse($tablePos, 'Testvoraussetzung: Seite muss eine Tabelle enthalten.');

        $wrapperPos = strrpos(substr($html, 0, $tablePos), '<div class="overflow-x-auto">');
        $this->assertNotFalse($wrapperPos, 'Die Tabelle muss von einem horizontal scrollbaren Container umschlossen sein, sonst wird sie auf schmalen Bildschirmen abgeschnitten.');
    }

    public function test_the_participants_table_scrolls_within_its_own_card_instead_of_being_cut_off(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->get(route('admin.dashboard'));
        $response->assertOk();

        $html = $response->getContent();
        $tablePos = strpos($html, '<table');
        $this->assertNotFalse($tablePos, 'Testvoraussetzung: Seite muss eine Tabelle enthalten.');

        $wrapperPos = strrpos(substr($html, 0, $tablePos), '<div class="overflow-x-auto">');
        $this->assertNotFalse($wrapperPos, 'Die Teilnehmer-Tabelle muss von einem horizontal scrollbaren Container umschlossen sein, sonst wird sie auf schmalen Bildschirmen abgeschnitten.');
    }
}
