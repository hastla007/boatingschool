<?php

namespace Tests\Feature;

use App\Models\TenantBranding;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Eigene Domain einer Bootsschule im Superadmin eintragen; die App erkennt
 * die Schule dann an dieser Domain.
 */
class TenantCustomDomainTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function domainOf($tenant): ?string
    {
        return $this->onAdmin(fn () => TenantBranding::query()->find($tenant->id)?->custom_domain);
    }

    public function test_the_superadmin_sets_a_custom_domain_and_the_school_answers_on_it(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant('Domain Schule');
        $other = $this->createTestTenant('Andere Schule');
        // Volle URL: nach einer Anfrage an die Schul-Domain gingen relative
        // Pfade im Test sonst ebenfalls an diese Domain.
        $update = fn ($t, $domain) => $this->actingAs($superadmin)->patch(rtrim(config('app.url'), '/')."/superadmin/tenants/{$t->id}", ['name' => $t->name, 'status' => 'active', 'custom_domain' => $domain]);

        $update($tenant, 'https://Lernen.E2E-Domainschule.de/')->assertSessionHasNoErrors();
        $this->assertSame('lernen.e2e-domainschule.de', $this->domainOf($tenant));

        // Andere Schule: dieselbe Domain, die zentrale Domain oder Unsinn → abgelehnt.
        $update($other, 'lernen.e2e-domainschule.de')->assertSessionHasErrors('custom_domain');
        $update($other, 'foo.'.config('app.central_domain'))->assertSessionHasErrors('custom_domain');
        $update($other, 'kein host')->assertSessionHasErrors('custom_domain');
        $this->assertNull($this->domainOf($other));

        auth()->logout();
        $this->get('http://lernen.e2e-domainschule.de/login')->assertOk()->assertSee('Domain Schule', false);

        // Leeren entfernt die Domain wieder.
        $update($tenant, '')->assertSessionHasNoErrors();
        $this->assertNull($this->domainOf($tenant));
    }
}
