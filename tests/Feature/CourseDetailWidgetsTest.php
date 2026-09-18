<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Zwei Werbe-/Info-Widgets unterhalb der Modul-Kacheln auf der Kurs-
 * Detailseite: Kontaktdaten der Bootsschule (nur wenn gepflegt) und die
 * "Lerne auch auf deinem Handy!"-Ankündigung für die noch nicht
 * existierende mobile App (bewusst ohne echte Store-Links).
 */
class CourseDetailWidgetsTest extends TestCase
{
    use InteractsWithTenants;

    public function test_contact_widget_is_hidden_when_no_contact_details_are_configured(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertDontSee('Fragen? Kontaktiere Deine Bootsschule!');
    }

    public function test_contact_widget_shows_phone_address_and_website_when_configured(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(fn () => $tenant->branding->update([
            'phone' => '+49 40 1234567',
            'phone_support_enabled' => true,
            'street' => 'Hafenstraße 1',
            'postal_code' => '20457',
            'city' => 'Hamburg',
            'country' => 'Deutschland',
            'website' => 'www.e2e-bootsschule.test',
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee('Fragen? Kontaktiere Deine Bootsschule!');
        $response->assertSee('+49 40 1234567');
        $response->assertSee('Hafenstraße 1');
        $response->assertSee('20457 Hamburg');
        $response->assertSee('www.e2e-bootsschule.test');
        $response->assertSee('https://www.e2e-bootsschule.test', false);
        $response->assertSee($tenant->name);
    }

    public function test_contact_widget_shows_the_tenant_logo_when_uploaded(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(function () use ($tenant) {
            $asset = MediaAsset::create([
                'asset_key' => 'test-tenant-logo-'.uniqid(),
                'media_type' => 'image',
                'storage_path' => 'http://localhost/storage/branding-logos/test-logo.png',
            ]);
            $tenant->branding->update(['logo_asset_id' => $asset->id, 'phone' => '+49 40 1234567', 'phone_support_enabled' => true]);
        });

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee('http://localhost/storage/branding-logos/test-logo.png', false);
    }

    public function test_whatsapp_hint_is_shown_next_to_the_contact_widget_when_enabled(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(fn () => $tenant->branding->update([
            'whatsapp_enabled' => true,
            'whatsapp_phone' => '491701234567',
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee('Frag uns auch direkt per WhatsApp!');
        $response->assertSee('https://wa.me/491701234567?text=', false);
        // Die Bootsschule hat noch keine Kontaktdaten hinterlegt, aber der
        // WhatsApp-Hinweis allein reicht schon aus, damit das Widget (mit
        // Logo-Spalte und Namen) angezeigt wird.
        $response->assertSee($tenant->name);
    }

    public function test_whatsapp_hint_is_hidden_when_whatsapp_support_is_not_enabled(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(fn () => $tenant->branding->update(['phone' => '+49 40 1234567', 'phone_support_enabled' => true]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertDontSee('Frag uns auch direkt per WhatsApp!');
        $response->assertDontSee('wa.me', false);
        $response->assertSee('Ruf uns einfach an!');
        $response->assertSee('captain-phone.webp', false);
        $response->assertSee('tel:+4940123456', false);
    }

    public function test_phone_number_is_hidden_when_phone_support_is_not_enabled(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(fn () => $tenant->branding->update([
            'phone' => '+49 40 1234567',
            'phone_support_enabled' => false,
            'website' => 'www.e2e-bootsschule.test',
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertDontSee('+49 40 1234567');
        $response->assertDontSee('Ruf uns einfach an!');
        $response->assertSee('Wir sind gerne für Dich da!');
    }

    public function test_support_email_is_shown_when_email_support_is_enabled(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(fn () => $tenant->branding->update([
            'support_email' => 'kontakt@e2e-bootsschule.test',
            'email_support_enabled' => true,
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee('kontakt@e2e-bootsschule.test');
        $response->assertSee('mailto:kontakt@e2e-bootsschule.test', false);
    }

    public function test_oder_separates_phone_and_email_when_both_are_enabled(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(fn () => $tenant->branding->update([
            'phone' => '+49 40 1234567',
            'phone_support_enabled' => true,
            'support_email' => 'kontakt@e2e-bootsschule.test',
            'email_support_enabled' => true,
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee('+49 40 1234567');
        $response->assertSee('oder');
        $response->assertSee('kontakt@e2e-bootsschule.test');
    }

    public function test_oder_is_not_shown_when_only_one_support_channel_is_enabled(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(fn () => $tenant->branding->update([
            'phone' => '+49 40 1234567',
            'phone_support_enabled' => true,
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertDontSee('oder');
    }

    public function test_support_email_is_hidden_when_email_support_is_not_enabled(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(fn () => $tenant->branding->update([
            'support_email' => 'kontakt@e2e-bootsschule.test',
            'email_support_enabled' => false,
            'website' => 'www.e2e-bootsschule.test',
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertDontSee('kontakt@e2e-bootsschule.test');
    }

    public function test_generic_captain_hint_is_shown_when_contact_details_have_no_phone_number(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(fn () => $tenant->branding->update(['website' => 'www.e2e-bootsschule.test']));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee('Wir sind gerne für Dich da!');
        $response->assertSee('captain-phone.webp', false);
    }

    public function test_captain_at_laptop_image_is_shown_next_to_the_whatsapp_hint(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(fn () => $tenant->branding->update([
            'whatsapp_enabled' => true,
            'whatsapp_phone' => '491701234567',
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee('captain-laptop.webp', false);
    }

    public function test_mobile_app_widget_is_always_shown_without_a_real_store_link(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();
        $response->assertSee('Lerne auch auf deinem Handy!');
        $response->assertSee('App Store');
        $response->assertSee('Google Play');
    }
}
