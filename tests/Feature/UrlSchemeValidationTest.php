<?php

namespace Tests\Feature;

use App\Models\AddonTemplate;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview N1: die bloße "url"-Validierungsregel akzeptiert eine
 * lange Liste von Schemata (u. a. javascript:, data:, file:), die später
 * unverändert als href in einer Blade-Ansicht landen.
 */
class UrlSchemeValidationTest extends TestCase
{
    use InteractsWithTenants;

    public function test_a_javascript_scheme_webshop_link_is_rejected(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $course = $this->existingCourse('SRC');

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/courses', [
            'enabled' => [$course->id],
            'links' => [$course->id => 'javascript:alert(document.cookie)'],
        ]);

        $response->assertSessionHasErrors('links.'.$course->id);
    }

    public function test_a_javascript_scheme_addon_offer_url_is_rejected(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $template = $this->onAdmin(fn () => AddonTemplate::firstOrFail());

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/addons', [
            'addons' => [
                $template->id => [
                    'active' => '1',
                    'offer_url' => 'javascript:alert(document.cookie)',
                ],
            ],
        ]);

        $response->assertSessionHasErrors('addons.'.$template->id.'.offer_url');
    }

    public function test_an_https_webshop_link_still_works(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $course = $this->existingCourse('SRC');

        $response = $this->actingAsInTenant($admin, $tenant)->patch('/admin/courses', [
            'enabled' => [$course->id],
            'links' => [$course->id => 'https://dein-webshop.de/kurs'],
        ]);

        $response->assertSessionDoesntHaveErrors();
    }
}
