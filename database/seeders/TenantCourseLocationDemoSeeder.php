<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\Tenant;
use App\Models\TenantCourseLocation;
use Illuminate\Database\Seeder;

/**
 * Demo-Bestückung der Praxis-/Prüfungsort-Paare (siehe TenantCourseLocation)
 * für die Demo-Bootsschule "mueller": zwei wählbare Praxisorte für die
 * Motorboot-Kurse -- einer mit identischem Prüfungsort, einer mit
 * abweichendem (Beispiel aus dem Fachkonzept: Praxis an Ort X, Prüfung an
 * Ort Y) -- zeigt, dass mehrere Orte pro Kurs möglich sind und jeder
 * Praxisort seinen eigenen fixen Prüfungsort mitbringt.
 */
class TenantCourseLocationDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'mueller')->first();

        $courseIds = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')
            ->whereIn('code', ['SBF-SEE', 'SBF-BIN-MOTOR', 'SBF-SEE-BIN'])
            ->pluck('id')->values()->all();

        if (! $tenant || empty($courseIds)) {
            return;
        }

        TenantCourseLocation::updateOrCreate(
            ['tenant_id' => $tenant->id, 'praxis_ort' => 'Hauptstandort Hamburg'],
            [
                'praxis_strasse' => 'Hafenstraße 12',
                'praxis_plz' => '20095',
                'praxis_stadt' => 'Hamburg',
                'praxis_land' => 'Deutschland',
                'praxis_lat' => 53.5460740,
                'praxis_lng' => 9.9826940,
                'pruefung_ort' => 'Hauptstandort Hamburg',
                'pruefung_strasse' => 'Hafenstraße 12',
                'pruefung_plz' => '20095',
                'pruefung_stadt' => 'Hamburg',
                'pruefung_land' => 'Deutschland',
                'pruefung_lat' => 53.5460740,
                'pruefung_lng' => 9.9826940,
                'course_ids' => $courseIds,
                'sort_order' => 1,
            ]
        );

        TenantCourseLocation::updateOrCreate(
            ['tenant_id' => $tenant->id, 'praxis_ort' => 'Trainingsgewässer Alster'],
            [
                'praxis_strasse' => 'Alsterufer 5',
                'praxis_plz' => '20354',
                'praxis_stadt' => 'Hamburg',
                'praxis_land' => 'Deutschland',
                'praxis_lat' => 53.5610000,
                'praxis_lng' => 9.9954000,
                'pruefung_ort' => 'Prüfstelle Wassersportzentrum',
                'pruefung_strasse' => 'Wassersportallee 3',
                'pruefung_plz' => '22527',
                'pruefung_stadt' => 'Hamburg',
                'pruefung_land' => 'Deutschland',
                'pruefung_lat' => 53.5511000,
                'pruefung_lng' => 9.8822000,
                'course_ids' => $courseIds,
                'sort_order' => 2,
            ]
        );
    }
}
