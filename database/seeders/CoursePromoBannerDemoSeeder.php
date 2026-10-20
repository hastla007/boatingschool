<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\CoursePromoBanner;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Demo-Bestückung für den Kurs-Empfehlungsbanner (siehe CoursePromoBanner):
 * auf der Kursseite von "SBF See & Binnen" wirbt die Demo-Bootsschule
 * "mueller" zusätzlich zum Addon-Banner (siehe AddonDemoSeeder) für den
 * SRC-Funkschein als sinnvolle Ergänzung -- zeigt, dass beide Banner-Arten
 * gleichzeitig auf einer Kursseite erscheinen können.
 */
class CoursePromoBannerDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'mueller')->first();
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE-BIN')->whereNull('tenant_id')->first();

        if (! $tenant || ! $course) {
            return;
        }

        CoursePromoBanner::updateOrCreate(
            ['tenant_id' => $tenant->id, 'course_id' => $course->id],
            [
                'active' => true,
                'headline' => 'Jetzt auch den Seefunk-Schein (SRC) sichern!',
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
            ]
        );
    }
}
