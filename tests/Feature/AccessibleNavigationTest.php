<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review Oberfläche U7: Barrierefreiheit -- Menüs ohne ARIA, kein
 * sichtbarer Fokus, kein Skip-Link.
 *
 * - Skip-Link: ein Tastatur-/Screenreader-Nutzer musste bisher die gesamte
 *   Navigation durchtaben, um zum eigentlichen Seiteninhalt zu gelangen.
 * - ARIA: die Dropdown-/Hamburger-Menüs hatten keine der Rollen/Attribute,
 *   die ein Screenreader braucht, um sie als Menü zu erkennen oder ihren
 *   Zustand (offen/geschlossen) mitzuteilen.
 * - Fokus: mehrere interaktive Elemente nutzten focus:outline-none ohne
 *   einen sichtbaren Ersatz-Fokusring.
 */
class AccessibleNavigationTest extends TestCase
{
    use InteractsWithTenants;

    public function test_learner_layout_has_a_skip_link_targeting_the_main_content(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertSeeInOrder(['href="#main-content"', 'Zum Hauptinhalt springen', 'id="main-content"'], false);
    }

    public function test_guest_layout_has_a_skip_link_targeting_the_main_content(): void
    {
        $tenant = $this->createTestTenant();

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/login');

        $response->assertOk();
        $response->assertSeeInOrder(['href="#main-content"', 'id="main-content"'], false);
    }

    public function test_superadmin_layout_has_a_skip_link_targeting_the_main_content(): void
    {
        $admin = $this->createSuperAdmin();

        $response = $this->actingAs($admin)->get('/superadmin');

        $response->assertOk();
        $response->assertSeeInOrder(['href="#main-content"', 'id="main-content"'], false);
    }

    public function test_dropdown_menus_expose_aria_roles_and_state(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('role="menu"', $content);
        $this->assertStringContainsString('role="menuitem"', $content);
        $this->assertStringContainsString(':aria-expanded="open"', $content);
        $this->assertStringContainsString('aria-haspopup="true"', $content);
    }

    public function test_mobile_hamburger_has_a_label_and_describes_its_controlled_panel(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('aria-label="Menü öffnen/schließen"', $content);
        $this->assertStringContainsString('aria-controls="mobile-menu"', $content);
        $this->assertStringContainsString('id="mobile-menu"', $content);
    }

    public function test_superadmin_hamburger_has_a_label_and_describes_its_controlled_panel(): void
    {
        $admin = $this->createSuperAdmin();

        $response = $this->actingAs($admin)->get('/superadmin');

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('aria-label="Menü öffnen/schließen"', $content);
        $this->assertStringContainsString('aria-controls="superadmin-mobile-menu"', $content);
        $this->assertStringContainsString('id="superadmin-mobile-menu"', $content);
    }

    public function test_dropdown_links_have_a_visible_focus_ring_instead_of_outline_none_alone(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('focus:ring-2 focus:ring-inset focus:ring-indigo-500', false);
    }
}
