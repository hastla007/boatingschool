<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview N5: der Tenant-Picker auf der zentralen Domain listete
 * bislang alle Mandanten öffentlich mit Name und Slug, auch suspended,
 * closed und trial.
 */
class TenantPickerTest extends TestCase
{
    use InteractsWithTenants;

    public function test_the_picker_only_lists_active_tenants(): void
    {
        $active = $this->createTestTenant('Aktive Schule');
        $suspended = $this->createTestTenant('Gesperrte Schule');
        $this->onAdmin(fn () => $suspended->update(['status' => 'suspended']));
        $trial = $this->createTestTenant('Test-Schule-Trial');
        $this->onAdmin(fn () => $trial->update(['status' => 'trial']));

        $response = $this->get('/', ['HTTP_HOST' => config('app.central_domain')]);

        $response->assertOk();
        $response->assertSee($active->name);
        $response->assertDontSee($suspended->name);
        $response->assertDontSee($trial->name);
    }
}
