<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductPurchase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WillkommensboxOrder;
use Illuminate\Database\Seeder;

/**
 * Demo-Bestückung für die Willkommensbox (siehe WillkommensboxOrder,
 * WillkommensboxController): Max Mustermann (siehe
 * UserAndEntitlementSeeder) bekommt Zugang zum Produkt
 * "Lernplattform (inkl. Willkommensbox)" und eine noch nicht versendete
 * Bestellung, damit die Teilnehmer-Detailseite im Bootsschul-Admin den
 * Willkommensbox-Bereich tatsächlich zeigt statt leer zu bleiben.
 */
class WillkommensboxDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'mueller')->first();
        $user = User::where('email', 'max@bootsschule-mueller.de')->first();
        $product = Product::where('code', 'LERNPLATTFORM-WB')->first();

        if (! $tenant || ! $user || ! $product) {
            return;
        }

        ProductPurchase::firstOrCreate([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);

        WillkommensboxOrder::firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            [
                'street' => 'Musterweg 12',
                'postal_code' => '20099',
                'city' => 'Hamburg',
                'country' => 'Deutschland',
                'note' => 'Bitte an der Pforte abgeben.',
            ]
        );
    }
}
