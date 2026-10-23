<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B17: Superadmin\TenantController::store() erlaubte per
 * alpha_dash Großbuchstaben und Unterstriche im Slug -- der Slug wird aber
 * direkt als Subdomain verwendet, und der dortige Tenant::where('slug', ...)
 * Vergleich ist case-sensitive, während Browser/DNS den Host immer
 * kleinschreiben. Eine Bootsschule mit Großbuchstaben im Slug war dadurch
 * über ihre eigentliche Subdomain nie erreichbar. Reservierte Namen wie
 * "www" oder "admin" wurden außerdem gar nicht gesperrt.
 */
class TenantSlugValidationTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_a_slug_with_uppercase_letters_is_rejected(): void
    {
        $response = $this->createTenantWithSlug('MeineSchule');

        $response->assertSessionHasErrors('slug');
        $this->assertDatabaseMissing('tenant', ['slug' => 'MeineSchule']);
    }

    public function test_a_slug_with_an_underscore_is_rejected(): void
    {
        $response = $this->createTenantWithSlug('meine_schule');

        $response->assertSessionHasErrors('slug');
        $this->assertDatabaseMissing('tenant', ['slug' => 'meine_schule']);
    }

    public function test_reserved_slugs_are_rejected(): void
    {
        foreach (['www', 'admin', 'superadmin'] as $reserved) {
            $response = $this->createTenantWithSlug($reserved);

            $response->assertSessionHasErrors('slug');
            $this->assertDatabaseMissing('tenant', ['slug' => $reserved]);
        }
    }

    public function test_a_lowercase_hyphenated_slug_is_still_accepted(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->post('/superadmin/tenants', [
            'slug' => 'b17-gueltiger-slug',
            'name' => 'B17 Gültiger Slug',
            'status' => 'active',
            'admin_first_name' => 'Test',
            'admin_last_name' => 'Admin',
            'admin_email' => 'b17-gueltiger-slug-admin@example.test',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tenant', ['slug' => 'b17-gueltiger-slug']);

        $this->onAdmin(function () {
            $tenant = Tenant::where('slug', 'b17-gueltiger-slug')->first();
            $this->createdTenantIds[] = $tenant->id;
            $admin = User::where('email', 'b17-gueltiger-slug-admin@example.test')->first();
            $this->createdUserIds[] = $admin->id;
        });
    }

    private function createTenantWithSlug(string $slug): TestResponse
    {
        $superadmin = $this->createSuperAdmin();

        return $this->actingAs($superadmin)->post('/superadmin/tenants', [
            'slug' => $slug,
            'name' => 'B17 Testschule',
            'status' => 'active',
            'admin_first_name' => 'Test',
            'admin_last_name' => 'Admin',
            'admin_email' => 'b17-'.str()->random(8).'@example.test',
        ]);
    }
}
