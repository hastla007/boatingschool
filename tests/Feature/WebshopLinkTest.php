<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\TenantCourseDisabled;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bootsschulen mit eigenem Webshop können pro Kurs einen Kauf-Link
 * hinterlegen, der auf der Kursübersicht den Standard-Hinweistext für
 * gesperrte Kurse ersetzt. Lebt seit der Zusammenlegung der beiden Tabs
 * auf derselben Seite/demselben Formular wie die Kursauswahl selbst
 * (siehe CourseSelectionController, admin/tabs/courses.blade.php) --
 * ein echtes Browser-Formular übermittelt deshalb immer "enabled[]" und
 * "links[]" zusammen, die Tests hier tun das ebenso.
 */
class WebshopLinkTest extends TestCase
{
    use InteractsWithTenants;

    /** Alle sitewide freigegebenen Kurs-IDs -- Standardwert für "enabled[]", damit ein Test, der nur Links prüft, nicht versehentlich alle Kurse abwählt. */
    private function allSiteEnabledCourseIds(): array
    {
        return $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('site_enabled', true)->pluck('id')->toArray());
    }

    public function test_a_school_admin_can_set_a_webshop_link_for_a_course(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/courses', [
            'enabled' => $this->allSiteEnabledCourseIds(),
            'links' => [$course->id => 'https://shop.example.test/src-kurs'],
        ]);

        $response->assertRedirect();
        $this->asTenant($tenant, function () use ($tenant, $course) {
            $this->assertDatabaseHas('course_webshop_link', [
                'tenant_id' => $tenant->id,
                'course_id' => $course->id,
                'url' => 'https://shop.example.test/src-kurs',
            ]);
        });
    }

    public function test_clearing_a_link_removes_it(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');
        $enabled = $this->allSiteEnabledCourseIds();

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/courses', [
            'enabled' => $enabled,
            'links' => [$course->id => 'https://shop.example.test/src-kurs'],
        ]);

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/courses', [
            'enabled' => $enabled,
            'links' => [$course->id => ''],
        ]);

        $this->asTenant($tenant, function () use ($tenant, $course) {
            $this->assertDatabaseMissing('course_webshop_link', [
                'tenant_id' => $tenant->id,
                'course_id' => $course->id,
            ]);
        });
    }

    public function test_course_overview_shows_jetzt_kaufen_when_a_link_is_configured(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/courses', [
            'enabled' => $this->allSiteEnabledCourseIds(),
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

        $this->actingAsInTenant($adminA, $tenantA)->patch('/admin/courses', [
            'enabled' => $this->allSiteEnabledCourseIds(),
            'links' => [$course->id => 'https://shop.example.test/src-kurs'],
        ]);

        $response = $this->actingAsInTenant($learnerB, $tenantB)->get('/courses');

        $response->assertOk();
        $response->assertDontSee('https://shop.example.test/src-kurs');
        $response->assertSee('Kein Zugang');
    }

    public function test_kursauswahl_tab_greys_out_the_link_field_for_a_disabled_course(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $disabledCourse = $this->existingCourse('SRC');
        $activeCourse = $this->existingCourse('UBI');
        $this->onAdmin(fn () => TenantCourseDisabled::create(['tenant_id' => $tenant->id, 'course_id' => $disabledCourse->id]));

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=courses');

        $response->assertOk();

        // Das übergeordnete Zeilen-Element trägt x-data="{ enabled: false }"
        // für den abgewählten Kurs -- das sorgt client-seitig (Alpine) für
        // das Ausgrauen des Link-Felds, auch ohne eigenen Hinweistext.
        preg_match('/x-data="\{ enabled: (true|false) \}"\s*>\s*<label[^>]*>\s*<input[^>]*>\s*<span[^>]*>\s*'.preg_quote($disabledCourse->name, '/').'/', $response->getContent(), $disabledMatch);
        preg_match('/x-data="\{ enabled: (true|false) \}"\s*>\s*<label[^>]*>\s*<input[^>]*>\s*<span[^>]*>\s*'.preg_quote($activeCourse->name, '/').'/', $response->getContent(), $activeMatch);
        $this->assertNotEmpty($disabledMatch, 'Zeile für den abgewählten Kurs nicht gefunden.');
        $this->assertNotEmpty($activeMatch, 'Zeile für den aktiven Kurs nicht gefunden.');
        $this->assertSame('false', $disabledMatch[1]);
        $this->assertSame('true', $activeMatch[1]);
    }

    public function test_link_for_a_disabled_course_can_still_be_saved(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');
        $this->onAdmin(fn () => TenantCourseDisabled::create(['tenant_id' => $tenant->id, 'course_id' => $course->id]));
        $enabledWithoutCourse = array_values(array_diff($this->allSiteEnabledCourseIds(), [$course->id]));

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/courses', [
            'enabled' => $enabledWithoutCourse,
            'links' => [$course->id => 'https://shop.example.test/fuer-spaeter'],
        ]);

        $response->assertRedirect();
        $this->asTenant($tenant, function () use ($tenant, $course) {
            $this->assertDatabaseHas('course_webshop_link', [
                'tenant_id' => $tenant->id,
                'course_id' => $course->id,
                'url' => 'https://shop.example.test/fuer-spaeter',
            ]);
            $this->assertDatabaseHas('tenant_course_disabled', [
                'tenant_id' => $tenant->id,
                'course_id' => $course->id,
            ]);
        });
    }
}
