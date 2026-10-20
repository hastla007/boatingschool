<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPurchase;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bestellformular für die Willkommensbox (siehe WillkommensboxController,
 * WillkommensboxOrder) -- nur erreichbar, wenn der Nutzer "Lernplattform
 * (inkl. Willkommensbox)" eingelöst hat (siehe CouponManagementTest für die
 * Einlöse-Seite, die diese ProductPurchase erzeugt).
 */
class WillkommensboxTest extends TestCase
{
    use InteractsWithTenants;

    private function grantWillkommensbox($tenant, $user): void
    {
        $product = $this->onAdmin(fn () => Product::where('code', 'LERNPLATTFORM-WB')->firstOrFail());
        $this->onAdmin(fn () => ProductPurchase::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'source_type' => 'coupon',
            'source_reference' => 'E2E-WB-CODE',
        ]));
    }

    public function test_menu_link_is_hidden_without_the_product(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('Willkommensbox');
    }

    public function test_menu_link_is_shown_once_the_product_is_granted(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantWillkommensbox($tenant, $learner);

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Willkommensbox');
        $response->assertSee(route('willkommensbox.index', absolute: false), false);
    }

    public function test_page_is_forbidden_without_the_product(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/willkommensbox');

        $response->assertForbidden();
    }

    public function test_form_is_prefilled_from_the_profile_address(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner', [
            'street' => 'Profilstraße 9', 'postal_code' => '99999', 'city' => 'Profilstadt', 'country' => 'Deutschland',
        ]);
        $this->grantWillkommensbox($tenant, $learner);

        $response = $this->actingAsInTenant($learner, $tenant)->get('/willkommensbox');

        $response->assertOk();
        $response->assertSee('Profilstraße 9');
        $response->assertSee('99999');
        $response->assertSee('Profilstadt');
    }

    public function test_learner_can_order_the_willkommensbox(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantWillkommensbox($tenant, $learner);

        $response = $this->actingAsInTenant($learner, $tenant)->post('/willkommensbox', [
            'street' => 'Lieferstraße 1',
            'postal_code' => '12345',
            'city' => 'Lieferstadt',
            'country' => 'Deutschland',
            'note' => 'Bitte beim Nachbarn abgeben.',
        ]);

        $response->assertRedirect(route('willkommensbox.index'));
        $this->onAdmin(fn () => $this->assertDatabaseHas('willkommensbox_order', [
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'street' => 'Lieferstraße 1',
            'postal_code' => '12345',
            'city' => 'Lieferstadt',
            'country' => 'Deutschland',
            'note' => 'Bitte beim Nachbarn abgeben.',
        ]));

        $confirmation = $this->actingAsInTenant($learner, $tenant)->get('/willkommensbox');
        $confirmation->assertOk();
        $confirmation->assertSee('Lieferstraße 1');
        $confirmation->assertSee('bestellt');
    }

    public function test_willkommensbox_cannot_be_ordered_twice(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantWillkommensbox($tenant, $learner);

        $this->actingAsInTenant($learner, $tenant)->post('/willkommensbox', [
            'street' => 'Erste Straße 1', 'postal_code' => '11111', 'city' => 'Erststadt', 'country' => 'Deutschland',
        ])->assertRedirect(route('willkommensbox.index'));

        $second = $this->actingAsInTenant($learner, $tenant)->post('/willkommensbox', [
            'street' => 'Zweite Straße 2', 'postal_code' => '22222', 'city' => 'Zweitstadt', 'country' => 'Deutschland',
        ]);

        $second->assertRedirect();
        $this->onAdmin(function () use ($tenant, $learner) {
            $this->assertSame(1, \App\Models\WillkommensboxOrder::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->count());
            $this->assertDatabaseHas('willkommensbox_order', ['tenant_id' => $tenant->id, 'user_id' => $learner->id, 'street' => 'Erste Straße 1']);
        });
    }

    public function test_required_fields_are_validated(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantWillkommensbox($tenant, $learner);

        $response = $this->actingAsInTenant($learner, $tenant)->post('/willkommensbox', []);

        $response->assertSessionHasErrors(['street', 'postal_code', 'city', 'country']);
    }

    public function test_order_is_forbidden_without_the_product(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->post('/willkommensbox', [
            'street' => 'Straße 1', 'postal_code' => '12345', 'city' => 'Stadt', 'country' => 'Deutschland',
        ]);

        $response->assertForbidden();
    }

    public function test_willkommensbox_is_isolated_per_tenant(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $learner = $this->createTenantUser($tenantA, 'learner');
        $this->onAdmin(fn () => \App\Models\TenantUser::create(['tenant_id' => $tenantB->id, 'user_id' => $learner->id, 'role' => 'learner', 'status' => 'active']));
        $this->grantWillkommensbox($tenantA, $learner);

        $responseA = $this->actingAsInTenant($learner, $tenantA)->get('/willkommensbox');
        $responseA->assertOk();

        $responseB = $this->actingAsInTenant($learner, $tenantB)->get('/willkommensbox');
        $responseB->assertForbidden();
    }
}
