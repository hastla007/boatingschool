<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Du/Sie im Admin-Bereich gemischt): der Bootsschul-Admin-
 * Bereich siezt durchgehend (siehe z. B. tabs/dashboard.blade.php,
 * tabs/branding.blade.php), acht Stellen in sieben Tabs duzten bisher
 * trotzdem -- Imperative ("Wähle aus"), Possessivpronomen ("deine
 * Bootsschule") und Anredeformen ("dich", "dir").
 */
class AdminAreaFormalAddressTest extends TestCase
{
    use InteractsWithTenants;

    public function test_the_dashboard_header_sizes_instead_of_duzt(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Verwalten Sie Teilnehmer, Kurse und Einstellungen Ihrer Bootsschule.');
        $response->assertDontSee('Verwalte Teilnehmer, Kurse und Einstellungen deiner Bootsschule.');
    }

    public function test_the_orte_tab_sizes_instead_of_duzt(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->get(route('admin.dashboard', ['tab' => 'orte']));

        $response->assertOk();
        $response->assertSee('Hier können Sie einen oder mehrere Orte für Praxis', false);
        $response->assertDontSee('Hier kannst du einen oder mehrere Orte', false);
    }

    public function test_the_addons_tab_sizes_instead_of_duzt(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->get(route('admin.dashboard', ['tab' => 'addons']));

        $response->assertOk();
        $response->assertSee('Hier können Sie vorgefertigte Addon-Banner aktivieren', false);
        $response->assertDontSee('Hier kannst du vorgefertigte Addon-Banner aktivieren', false);
        $response->assertSee('Link zu Ihrer Angebotsseite', false);
    }

    public function test_the_course_promo_banners_tab_sizes_instead_of_duzt(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->get(route('admin.dashboard', ['tab' => 'course-promo-banners']));

        $response->assertOk();
        $response->assertSee('Hier können Sie frei formulierte Banner anlegen', false);
        $response->assertDontSee('Hier kannst du frei formulierte Banner anlegen', false);
    }

    public function test_the_courses_tab_sizes_instead_of_duzt(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->get(route('admin.dashboard', ['tab' => 'courses']));

        $response->assertOk();
        $response->assertSee('Wählen Sie aus, welche Kurse Ihre Bootsschule anbietet.', false);
        $response->assertDontSee('Wähle aus, welche Kurse deine Bootsschule anbietet.', false);
        $response->assertSee('sich an Sie zu wenden', false);
        $response->assertSee('https://ihr-webshop.de/verlaengerung', false);
    }

    public function test_the_praxis_anmeldungen_tab_sizes_instead_of_duzt(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->get(route('admin.dashboard', ['tab' => 'praxis-anmeldungen']));

        $response->assertOk();
        $response->assertSee('Diese Daten dienen Ihnen als Grundlage', false);
        $response->assertDontSee('Diese Daten dienen dir als Grundlage', false);
    }

    public function test_the_willkommensbox_tab_sizes_instead_of_duzt(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->get(route('admin.dashboard', ['tab' => 'willkommensbox']));

        $response->assertOk();
        $response->assertSee('Bestellungen der Willkommensbox Ihrer Lerner.');
        $response->assertDontSee('Bestellungen der Willkommensbox deiner Lerner.');
    }

    /**
     * Bug-Review T8: nutzte bisher die informelle ihr-Form ("euch", "ihr",
     * "euren") statt der formellen Sie-Form.
     */
    public function test_the_coupons_tab_sizes_instead_of_the_informal_ihr_form(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->get(route('admin.dashboard', ['tab' => 'coupons']));

        $response->assertOk();
        $response->assertSee('Eigene Coupon-Codes bekommen Sie auf zwei Wegen', false);
        $response->assertDontSee('Eigene Coupon-Codes bekommt ihr auf zwei Wegen', false);
    }

    /**
     * Bug-Review T8: "eurer Bootsschule"/"eure Bootsschule" ist die
     * informelle ihr-Form. Die Row-Level-Security auf app_user
     * (app_user_select_visibility) macht einen Nutzer ohne tenant_user-
     * Zeile für die aktuelle Bootsschule bereits für
     * User::findOrFail() unsichtbar (ModelNotFoundException, bevor
     * dieser abort_unless()-Zweig überhaupt erreicht wird, siehe
     * CrossTenantAccountAccessTest) -- genau wie beim durch $el
     * übergebenen toggleFavorite()-Aufruf (Bug-Review Hoch, siehe
     * ExamFavoriteToggleTest) lässt sich dieser konkrete Text deshalb
     * nicht über einen HTTP-Request auslösen; der Test pinnt ihn
     * stattdessen im Quelltext fest.
     */
    public function test_the_non_member_entitlement_error_sizes_instead_of_the_informal_ihr_form(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Admin/EntitlementController.php'));

        $this->assertStringContainsString('Dieser Nutzer gehört nicht zu Ihrer Bootsschule.', $source);
        $this->assertStringContainsString('Dieser Kurs ist für Ihre Bootsschule nicht verfügbar.', $source);
        $this->assertStringNotContainsString('eurer Bootsschule', $source);
        $this->assertStringNotContainsString('eure Bootsschule', $source);
    }
}
