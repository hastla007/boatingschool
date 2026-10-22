<?php

namespace Tests\Feature;

use App\Models\TenantBranding;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview N9: die Custom-Domain-Auflösung (whereHas('branding'))
 * konnte unter der RLS-beschränkten App-Rolle nie greifen, weil
 * TenantContext an dieser Stelle gerade erst auf die Nil-UUID geleert
 * wurde -- tenant_branding war für die App-Rolle also immer leer.
 * Funktional kaputt, kein Spoofing-Risiko.
 */
class CustomDomainResolutionTest extends TestCase
{
    use InteractsWithTenants;

    public function test_a_request_to_a_configured_custom_domain_resolves_the_matching_tenant(): void
    {
        $tenant = $this->createTestTenant();
        $this->onAdmin(fn () => TenantBranding::where('tenant_id', $tenant->id)
            ->update(['custom_domain' => 'kurse.bootsschule-test.de']));

        // /register ist nicht mandantenunabhängig (anders als /login) --
        // ohne aufgelösten Tenant liefert ResolveTenant hier 404.
        $response = $this->get('http://kurse.bootsschule-test.de/register');

        $response->assertOk();
    }

    public function test_an_unconfigured_custom_domain_still_returns_not_found(): void
    {
        $this->createTestTenant();

        $response = $this->get('http://unbekannte-domain.example.test/register');

        $response->assertNotFound();
    }
}
