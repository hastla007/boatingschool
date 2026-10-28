<?php

namespace Tests\Feature;

use App\Models\WillkommensboxOrder;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review F6: Willkommensbox-Bestellungen ließen sich bisher nur
 * einzeln auf der Teilnehmer-Detailseite finden -- keine Gesamtübersicht
 * über alle Bestellungen der Bootsschule. Der "Willkommensbox"-Tab im
 * Bootsschul-Admin schließt diese Lücke.
 */
class WillkommensboxOrdersTabTest extends TestCase
{
    use InteractsWithTenants;

    public function test_admin_sees_willkommensbox_orders_of_their_own_tenant(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'E2E Lerner']);
        $this->onAdmin(fn () => WillkommensboxOrder::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'street' => 'Lieferstraße 1',
            'postal_code' => '12345',
            'city' => 'Lieferstadt',
            'country' => 'Deutschland',
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=willkommensbox');

        $response->assertOk();
        $response->assertSee('E2E Lerner');
        $response->assertSee('Lieferstraße 1');
        $response->assertSee('Offen');
    }

    public function test_orders_are_isolated_per_tenant(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminA = $this->createTenantUser($tenantA, 'owner');
        $learnerB = $this->createTenantUser($tenantB, 'learner', ['display_name' => 'Fremder Lerner']);
        $this->onAdmin(fn () => WillkommensboxOrder::create([
            'tenant_id' => $tenantB->id,
            'user_id' => $learnerB->id,
            'street' => 'Andere Straße 2',
            'postal_code' => '54321',
            'city' => 'Andere Stadt',
            'country' => 'Deutschland',
        ]));

        $response = $this->actingAsInTenant($adminA, $tenantA)->get('/admin?tab=willkommensbox');

        $response->assertOk();
        $response->assertDontSee('Fremder Lerner');
    }

    public function test_search_filters_orders_by_name(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $match = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Findbar Franz']);
        $noMatch = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Anderer Anton']);
        [$matchOrder, $noMatchOrder] = $this->onAdmin(function () use ($tenant, $match, $noMatch) {
            $matchOrder = WillkommensboxOrder::create(['tenant_id' => $tenant->id, 'user_id' => $match->id, 'street' => 'A', 'postal_code' => '1', 'city' => 'A', 'country' => 'Deutschland']);
            $noMatchOrder = WillkommensboxOrder::create(['tenant_id' => $tenant->id, 'user_id' => $noMatch->id, 'street' => 'B', 'postal_code' => '2', 'city' => 'B', 'country' => 'Deutschland']);

            return [$matchOrder, $noMatchOrder];
        });

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=willkommensbox&wb_search=Findbar');

        // Beide Lernernamen tauchen im übrigen Dashboard (z. B. Teilnehmer-
        // Tab) ohnehin auf, da alle Tabs serverseitig gerendert und nur per
        // Alpine ein-/ausgeblendet werden -- daher hier über den eindeutigen
        // Ship-Link (enthält die Order-UUID) statt über den Namen geprüft,
        // ob der Filter tatsächlich wirkt (siehe PraxisAnmeldungenTabTest).
        $response->assertOk();
        $response->assertSee(route('admin.willkommensbox-orders.ship', $matchOrder));
        $response->assertDontSee(route('admin.willkommensbox-orders.ship', $noMatchOrder));
    }

    public function test_open_orders_are_listed_before_shipped_ones(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $shippedLearner = $this->createTenantUser($tenant, 'learner');
        $openLearner = $this->createTenantUser($tenant, 'learner');
        [$shippedOrder, $openOrder] = $this->onAdmin(function () use ($tenant, $shippedLearner, $openLearner) {
            $shippedOrder = WillkommensboxOrder::create([
                'tenant_id' => $tenant->id, 'user_id' => $shippedLearner->id,
                'street' => 'A', 'postal_code' => '1', 'city' => 'A', 'country' => 'Deutschland',
                'created_at' => now()->subDays(5), 'shipped_at' => now()->subDay(),
            ]);
            $openOrder = WillkommensboxOrder::create([
                'tenant_id' => $tenant->id, 'user_id' => $openLearner->id,
                'street' => 'B', 'postal_code' => '2', 'city' => 'B', 'country' => 'Deutschland',
                'created_at' => now()->subDays(2),
            ]);

            return [$shippedOrder, $openOrder];
        });

        $response = $this->actingAsInTenant($admin, $tenant)->get('/admin?tab=willkommensbox');

        $response->assertOk();
        $content = $response->getContent();
        $this->assertLessThan(
            strpos($content, (string) $shippedOrder->id),
            strpos($content, (string) $openOrder->id)
        );
    }
}
