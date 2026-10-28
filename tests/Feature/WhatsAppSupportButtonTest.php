<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * "Click-to-Chat"-WhatsApp-Support-Button: erscheint nur für eingeloggte
 * Schüler, wenn die Bootsschule ihn aktiviert und eine Telefonnummer
 * hinterlegt hat, und verlinkt auf einen wa.me-Link mit vorausgefülltem,
 * URL-kodiertem Begrüßungstext.
 */
class WhatsAppSupportButtonTest extends TestCase
{
    use InteractsWithTenants;

    public function test_button_is_hidden_when_whatsapp_support_is_not_configured(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('wa.me', false);
    }

    public function test_button_is_hidden_when_enabled_but_no_phone_number_is_set(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $this->onAdmin(fn () => $tenant->branding->update(['whatsapp_enabled' => true]));

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('wa.me', false);
    }

    public function test_button_shows_a_wa_me_link_with_the_students_name_when_configured(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $this->onAdmin(fn () => $tenant->branding->update([
            'whatsapp_enabled' => true,
            'whatsapp_phone' => '491701234567',
            'whatsapp_greeting' => 'Hallo {name}, ich habe eine Frage zu {kurs}.',
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('https://wa.me/491701234567?text=', false);

        $expectedText = rawurlencode(str_replace(
            ['{name}', '{kurs}'],
            [$learner->name, 'meinem Kurs'],
            'Hallo {name}, ich habe eine Frage zu {kurs}.'
        ));
        $response->assertSee($expectedText, false);
    }

    public function test_button_label_shows_the_tenants_name_instead_of_a_generic_label(): void
    {
        $tenant = $this->createTestTenant('Bootsschule Müller');
        $learner = $this->createTenantUser($tenant, 'learner');

        $this->onAdmin(fn () => $tenant->branding->update([
            'whatsapp_enabled' => true,
            'whatsapp_phone' => '491701234567',
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Bootsschule Müller');
        $response->assertDontSee('>Support<', false);
    }

    public function test_greeting_includes_the_course_name_on_a_course_page(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(fn () => $tenant->branding->update([
            'whatsapp_enabled' => true,
            'whatsapp_phone' => '491701234567',
            'whatsapp_greeting' => 'Hallo {name}, ich habe eine Frage zu {kurs}.',
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");

        $response->assertOk();

        $expectedText = rawurlencode(str_replace(
            ['{name}', '{kurs}'],
            [$learner->name, $course->name],
            'Hallo {name}, ich habe eine Frage zu {kurs}.'
        ));
        $response->assertSee($expectedText, false);
    }

    public function test_button_is_not_shown_to_guests(): void
    {
        $tenant = $this->createTestTenant();

        $this->onAdmin(fn () => $tenant->branding->update([
            'whatsapp_enabled' => true,
            'whatsapp_phone' => '491701234567',
        ]));

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertDontSee('wa.me', false);
    }

    public function test_button_is_not_shown_on_the_bootsschul_admin_pages(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $this->onAdmin(fn () => $tenant->branding->update([
            'whatsapp_enabled' => true,
            'whatsapp_phone' => '491701234567',
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin');

        $response->assertOk();
        $response->assertDontSee('wa.me', false);
    }

    /**
     * Bug-Review Oberfläche U6: #25D366 (WhatsApp-Logo-Grün) erreichte mit
     * weißer Schrift/Icon nur 2,0:1 Kontrast (WCAG AA verlangt 4,5:1).
     */
    public function test_button_uses_the_darker_high_contrast_whatsapp_green(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $this->onAdmin(fn () => $tenant->branding->update([
            'whatsapp_enabled' => true,
            'whatsapp_phone' => '491701234567',
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('background-color: #075E54', false);
        $response->assertDontSee('#25D366', false);
    }
}
