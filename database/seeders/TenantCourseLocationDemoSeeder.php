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
                'pruefung_ort' => 'Hauptstandort Hamburg',
                'course_ids' => $courseIds,
                'sort_order' => 1,
            ]
        );

        TenantCourseLocation::updateOrCreate(
            ['tenant_id' => $tenant->id, 'praxis_ort' => 'Trainingsgewässer Alster'],
            [
                'pruefung_ort' => 'Prüfstelle Wassersportzentrum',
                'course_ids' => $courseIds,
                'sort_order' => 2,
            ]
        );
    }
}
