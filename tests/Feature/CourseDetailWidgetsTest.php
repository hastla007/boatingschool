<?php

namespace Tests\Feature;

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
