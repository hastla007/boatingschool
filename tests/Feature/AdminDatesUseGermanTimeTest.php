<?php

namespace Tests\Feature;

use App\Models\Entitlement;
use App\Models\Product;
use App\Models\ProductPurchase;
use App\Models\WillkommensboxOrder;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Admin-Datumsangaben in UTC): Buchungsdatum, Willkommensbox
 * "Bestellt am"/"Versendet am" (Teilnehmer-Detailseite), "Kürzlich
 * gebucht" (Dashboard) und "Versendet" (Willkommensbox-Tab) nutzten
 * ->format() direkt auf dem UTC-Zeitstempel -- zwischen 0 und 2 Uhr
 * deutscher Zeit stand dort noch der Vortag. Jetzt über GermanTime.
 */
class AdminDatesUseGermanTimeTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_participant_detail_page_shows_booking_and_willkommensbox_dates_in_german_time(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');

        // 23:30 UTC = 00:30 MEZ des Folgetags.
        $entitlement = $this->grantEntitlement($tenant, $learner, $this->existingCourse('SRC'));
        $this->onAdmin(fn () => Entitlement::where('id', $entitlement->id)->update(['valid_from' => Carbon::parse('2026-01-14 23:30:00', 'UTC')]));

        $this->grantWillkommensbox($tenant, $learner);
        $this->onAdmin(function () use ($tenant, $learner) {
            $order = WillkommensboxOrder::create([
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'street' => 'Lieferstraße 1',
                'postal_code' => '12345',
                'city' => 'Lieferstadt',
                'country' => 'Deutschland',
                'shipped_at' => Carbon::parse('2026-03-10 23:30:00', 'UTC'),
            ]);
            WillkommensboxOrder::where('id', $order->id)->update(['created_at' => Carbon::parse('2026-02-10 23:30:00', 'UTC')]);
        });

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.participants.show', $learner));

        $response->assertOk();
        $response->assertSee('15.01.2026');
        $response->assertDontSee('14.01.2026');
        $response->assertSee('11.02.2026');
        $response->assertDontSee('10.02.2026');
        $response->assertSee('Versendet am 11.03.2026');
        $response->assertDontSee('10.03.2026');
    }

    public function test_dashboard_recent_bookings_use_german_time(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');

        $utc = Carbon::now('UTC')->subDays(3)->setTime(23, 30);
        $entitlement = $this->grantEntitlement($tenant, $learner, $this->existingCourse('SRC'));
        $this->onAdmin(fn () => Entitlement::where('id', $entitlement->id)->update(['valid_from' => $utc]));

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee($utc->copy()->setTimezone('Europe/Berlin')->format('d.m.Y'));
        $response->assertDontSee($utc->format('d.m.Y'));
    }

    public function test_willkommensbox_tab_shows_the_shipping_date_in_german_time(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => WillkommensboxOrder::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'street' => 'Lieferstraße 1',
            'postal_code' => '12345',
            'city' => 'Lieferstadt',
            'country' => 'Deutschland',
            'shipped_at' => Carbon::parse('2026-03-10 23:30:00', 'UTC'),
        ]));

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard', ['tab' => 'willkommensbox']));

        $response->assertOk();
        $response->assertSee('Versendet 11.03.2026');
        $response->assertDontSee('Versendet 10.03.2026');
    }

    private function grantWillkommensbox($tenant, $user): void
    {
        $product = $this->onAdmin(fn () => Product::where('code', 'LERNPLATTFORM-WB')->firstOrFail());
        $this->onAdmin(fn () => ProductPurchase::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'source_type' => 'coupon',
            'source_reference' => 'E2E-WB-CODE-'.uniqid(),
        ]));
    }
}
