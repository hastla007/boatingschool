<?php

namespace Tests\Feature;

use App\Models\TenantCourseDisabled;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bootsschulen mit eigenem Webshop können pro Kurs einen Kauf-Link
 * hinterlegen, der auf der Kursübersicht den Standard-Hinweistext für
 * gesperrte Kurse ersetzt.
 */
class WebshopLinkTest extends TestCase
{
    use InteractsWithTenants;

    public function test_a_school_admin_can_set_a_webshop_link_for_a_course(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/webshop-links', [
            'links' => [$course->id => 'https://shop.example.test/src-kurs'],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('course_webshop_link', [
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'url' => 'https://shop.example.test/src-kurs',
        ]);
    }

    public function test_clearing_a_link_removes_it(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/webshop-links', [
            'links' => [$course->id => 'https://shop.example.test/src-kurs'],
        ]);

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/webshop-links', [
            'links' => [$course->id => ''],
        ]);

        $this->assertDatabaseMissing('course_webshop_link', [
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_course_overview_shows_jetzt_kaufen_when_a_link_is_configured(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/webshop-links', [
            'links' => [$course->id => 'https://shop.example.test/src-kurs'],
        ]);

        $response = $this->actingAsInTenant($learner, $tenant)->get('/courses');

        $response->assertOk();
        $response->assertSee('https://shop.example.test/src-kurs');
        $response->assertSee('Jetzt kaufen');
    }

    public function test_course_overview_shows_the_default_message_without_a_link(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/courses');

        $response->assertOk();
        $response->assertSee('Kein Zugang');
        $response->assertDontSee('Jetzt kaufen');
    }

    public function test_webshop_links_are_isolated_per_tenant(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminA = $this->createTenantUser($tenantA, 'owner');
        $learnerB = $this->createTenantUser($tenantB, 'learner');
        $course = $this->existingCourse('SRC');

        $this->actingAsInTenant($adminA, $tenantA)->patch('/admin/webshop-links', [
            'links' => [$course->id => 'https://shop.example.test/src-kurs'],
        ]);

        $response = $this->actingAsInTenant($learnerB, $tenantB)->get('/courses');

        $response->assertOk();
        $response->assertDontSee('https://shop.example.test/src-kurs');
        $response->assertSee('Kein Zugang');
    }

    public function test_webshop_links_tab_greys_out_a_course_disabled_in_kursauswahl(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $disabledCourse = $this->existingCourse('SRC');
        $activeCourse = $this->existingCourse('UBI');
        $this->onAdmin(fn () => TenantCourseDisabled::create(['tenant_id' => $tenant->id, 'course_id' => $disabledCourse->id]));

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=webshop-links');

        $response->assertOk();
        $response->assertSee('(Kurs aktuell abgewählt)');

        // Nur das umschließende div des abgewählten Kurses soll
        // "opacity-50" tragen -- das des aktiven Kurses nicht.
        preg_match('/<div([^>]*)>\s*<label[^>]*>\s*'.preg_quote($disabledCourse->name, '/').'/', $response->getContent(), $disabledMatch);
        preg_match('/<div([^>]*)>\s*<label[^>]*>\s*'.preg_quote($activeCourse->name, '/').'/', $response->getContent(), $activeMatch);
        $this->assertNotEmpty($disabledMatch, 'Feld für den abgewählten Kurs nicht gefunden.');
        $this->assertNotEmpty($activeMatch, 'Feld für den aktiven Kurs nicht gefunden.');
        $this->assertStringContainsString('opacity-50', $disabledMatch[1]);
        $this->assertStringNotContainsString('opacity-50', $activeMatch[1]);
    }

    public function test_link_for_a_disabled_course_can_still_be_saved(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');
        $this->onAdmin(fn () => TenantCourseDisabled::create(['tenant_id' => $tenant->id, 'course_id' => $course->id]));

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/webshop-links', [
            'links' => [$course->id => 'https://shop.example.test/fuer-spaeter'],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('course_webshop_link', [
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'url' => 'https://shop.example.test/fuer-spaeter',
        ]);
    }
}
