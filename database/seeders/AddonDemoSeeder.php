<?php

namespace Database\Seeders;

use App\Models\AddonTemplate;
use App\Models\CourseDefinition;
use App\Models\Tenant;
use App\Models\TenantAddon;
use Illuminate\Database\Seeder;

/**
 * Demo-Bestückung für das Addon-Banner-Feature (siehe AddonTemplate,
 * TenantAddon): eine Vorlage "Großes Paket", wie im Vorbild-Screenshot
 * eines Wettbewerbers, aktiviert für die Demo-Bootsschule "mueller" auf
 * dem Kombikurs "SBF See & Binnen" -- genau der Kurs, für den Max
 * Mustermann (siehe UserAndEntitlementSeeder) bereits ein Entitlement hat.
 */
class AddonDemoSeeder extends Seeder
{
    public function run(): void
    {
        $template = AddonTemplate::firstOrCreate(
            ['title' => 'Großes Paket'],
            [
                'badge_text' => 'BESTSELLER',
                'stat_text' => '81% wählen diese Option',
                'headline' => 'Sichere dir jetzt das große Paket',
                'bullets' => [
                    'Alle Praxisstunden inklusive',
                    'Theorie- und Praxisprüfung aus einer Hand',
                    'Kostenloser Wiederholungstermin bei Nichtbestehen',
                    'Persönliche Lernbetreuung bis zur Prüfung',
                ],
                'cta_label' => 'Großes Paket sichern',
                'sort_order' => 1,
                'active' => true,
            ]
        );

        $tenant = Tenant::where('slug', 'mueller')->first();
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE-BIN')->whereNull('tenant_id')->first();

        if (! $tenant || ! $course) {
            return;
        }

        TenantAddon::updateOrCreate(
            ['tenant_id' => $tenant->id, 'addon_template_id' => $template->id],
            [
                'active' => true,
                'coupon_code' => 'PAKET20',
                'price_label' => '299 €',
                'discount_percent' => 20,
                'trigger_course_id' => $course->id,
                'trigger_percent' => 20,
                'offer_url' => 'https://bootsschule-mueller.de/angebote/grosses-paket',
            ]
        );
    }
}
