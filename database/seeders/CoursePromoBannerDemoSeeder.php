<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\CoursePromoBanner;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Demo-Bestückung für den Kurs-Empfehlungsbanner (siehe CoursePromoBanner):
 * die Demo-Bootsschule "mueller" wirbt mit einem einzigen Banner
 * gleichzeitig auf den Kursseiten "SBF See & Binnen" und "SBF See" für den
 * SRC-Funkschein als sinnvolle Ergänzung -- zeigt, dass ein Banner mehrere
 * Kursseiten gleichzeitig bespielen kann und zusätzlich zum Addon-Banner
 * (siehe AddonDemoSeeder) erscheint.
 */
class CoursePromoBannerDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'mueller')->first();

        $courseIds = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')
            ->whereIn('code', ['SBF-SEE-BIN', 'SBF-SEE'])
            ->pluck('id')
            ->values()
            ->all();

        if (! $tenant || empty($courseIds)) {
            return;
        }

        CoursePromoBanner::updateOrCreate(
            ['tenant_id' => $tenant->id, 'headline' => 'Jetzt auch den Seefunk-Schein (SRC) sichern!'],
            [
                'active' => true,
                'description' => 'Perfekt in Kombination mit deinem Bootsführerschein.',
                'bullets' => [
                    'Nur 12 offizielle Prüfungsbögen',
                    'In 30 Minuten machbar',
                ],
                'cta_label' => 'Jetzt entdecken',
                'price_label' => '189 €',
                'discount_percent' => 15,
                'coupon_code' => 'SRC15',
                'offer_url' => 'https://bootsschule-mueller.de/angebote/src',
                'course_ids' => $courseIds,
                'trigger_percent' => 0,
            ]
        );
    }
}
