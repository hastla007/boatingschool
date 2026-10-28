<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review Oberfläche U5: Barrierefreiheit -- auf 23 Seiten stand ein
 * <label> nur visuell neben einem Formularfeld, ohne per for/id (oder
 * Umschließung bzw. aria-label) damit verknüpft zu sein -- ein Screenreader
 * konnte das Feld nicht benennen. Dieser Test prüft stellvertretend eine
 * Auswahl der betroffenen Seiten je Bereich (Bootsschul-Admin, Superadmin,
 * Lerner-Oberfläche), nicht jede einzelne der 23 Seiten.
 */
class FormFieldLabelAssociationTest extends TestCase
{
    use InteractsWithTenants;

    public function test_admin_dashboard_tabs_have_labels_linked_to_their_fields(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->get('/admin');

        $response->assertOk();
        $content = $response->getContent();

        // Branding-Tab: Farben (U4) + Adresse + Support/Kontakt-Felder.
        $this->assertStringContainsString('for="primary_color"', $content);
        $this->assertStringContainsString('id="primary_color"', $content);
        $this->assertStringContainsString('for="support_email"', $content);
        $this->assertStringContainsString('for="street"', $content);
        $this->assertStringContainsString('for="country"', $content);
        $this->assertStringContainsString('for="whatsapp_phone"', $content);
        $this->assertStringContainsString('for="exam_readiness_threshold_percent"', $content);

        // Teilnehmer-Tab: Einladungsformular.
        $this->assertStringContainsString('for="invite_name"', $content);
        $this->assertStringContainsString('for="invite_email"', $content);
        $this->assertStringContainsString('for="invite_code"', $content);

        // Coupon-Tab: Import-Textarea + Filterleiste.
        $this->assertStringContainsString('for="codes"', $content);
        $this->assertStringContainsString('for="coupon_search"', $content);
        $this->assertStringContainsString('for="coupon_status"', $content);

        // Willkommensbox-Tab: Suchfeld.
        $this->assertStringContainsString('for="wb_search"', $content);

        // Praxis-Anmeldungen-Tab: Suchfeld + Kursfilter.
        $this->assertStringContainsString('for="anmeldung_search"', $content);
        $this->assertStringContainsString('for="anmeldung_course"', $content);
    }

    public function test_profile_edit_page_has_labels_linked_to_address_fields(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/profile');

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('for="street"', $content);
        $this->assertStringContainsString('for="postal_code"', $content);
        $this->assertStringContainsString('for="city"', $content);
        $this->assertStringContainsString('for="country"', $content);
    }

    public function test_coupon_redeem_page_has_a_label_linked_to_the_code_field(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/coupons/redeem');

        $response->assertOk();
        $response->assertSee('for="code"', false);
        $response->assertSee('id="code"', false);
    }

    public function test_superadmin_users_index_has_a_label_linked_to_the_search_field(): void
    {
        $admin = $this->createSuperAdmin();

        $response = $this->actingAs($admin)->get('/superadmin/users');

        $response->assertOk();
        $response->assertSee('for="user_search"', false);
        $response->assertSee('id="user_search"', false);
    }
}
