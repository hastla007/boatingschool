<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\TenantBranding;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    /**
     * Zwei Demo-Bootsschulen mit deutlich unterschiedlichem Branding, um
     * White-Label-Trennung sichtbar zu testen (Sprint 2 Definition of Done).
     */
    public function run(): void
    {
        $tenants = [
            [
                'slug' => 'mueller',
                'name' => 'Bootsschule Müller',
                'branding' => [
                    'primary_color' => '#005FD7',
                    'secondary_color' => '#00A8A8',
                    'support_email' => 'info@bootsschule-mueller.de',
                    'legal_name' => 'Bootsschule Müller GmbH',
                ],
            ],
            [
                'slug' => 'hanse-kiel',
                'name' => 'Hanse Bootsschule Kiel',
                'branding' => [
                    'primary_color' => '#C2410C',
                    'secondary_color' => '#0F172A',
                    'support_email' => 'kontakt@hanse-bootsschule-kiel.de',
                    'legal_name' => 'Hanse Bootsschule Kiel e.K.',
                ],
            ],
        ];

        foreach ($tenants as $definition) {
            $tenant = Tenant::updateOrCreate(
                ['slug' => $definition['slug']],
                ['name' => $definition['name'], 'status' => 'active', 'default_locale' => 'de-DE']
            );

            TenantBranding::updateOrCreate(
                ['tenant_id' => $tenant->id],
                $definition['branding']
            );
        }
    }
}
