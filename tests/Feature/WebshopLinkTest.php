<?php

namespace Tests\Feature;

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
}
