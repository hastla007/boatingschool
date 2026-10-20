<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\Tenant;
use App\Models\TenantCourseLocation;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo-Bestückung für eingegangene Praxisformular-Anmeldungen (siehe
 * PraxisFormSubmission, PraxisFormSubmissionController::store()): Max
 * Mustermann meldet sich für "SBF See & Binnen" am Hauptstandort Hamburg
 * an, damit der Tab "Praxis-Anmeldungen" im Bootsschul-Admin nicht leer
 * bleibt. Die "data"-Struktur folgt exakt dem, was der echte Absende-Weg
 * (inkl. Auflösung des Ort-Feldes auf Praxis-/Prüfungsadresse) erzeugen
 * würde.
 */
class PraxisAnmeldungDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'mueller')->first();
        $user = User::where('email', 'max@bootsschule-mueller.de')->first();
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE-BIN')->whereNull('tenant_id')->first();
        $template = PraxisFormTemplate::where('title', 'Praxis & Prüfung Motorboot')->first();
        $location = TenantCourseLocation::where('tenant_id', $tenant?->id)->where('praxis_ort', 'Hauptstandort Hamburg')->first();

        if (! $tenant || ! $user || ! $course || ! $template || ! $location) {
            return;
        }

        PraxisFormSubmission::firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'course_id' => $course->id],
            [
                'praxis_form_template_id' => $template->id,
                'data' => [
                    'ort' => $location->praxis_ort,
                    'ort_adresse' => $location->praxisAdresse(),
                    'ort_pruefungsort' => $location->pruefung_ort,
                    'ort_pruefungsort_adresse' => $location->pruefungAdresse(),
                    'wunschdatum' => now()->addWeeks(6)->toDateString(),
                    'geburtsort' => 'Hamburg',
                    'nationalitaet' => 'Deutsch',
                    'telefonnummer' => '+49 170 1234567',
                    'geschlecht' => 'Männlich',
                    'tauglichkeit_vorhanden' => true,
                    'vorlaeufiger_fuehrerschein' => false,
                ],
            ]
        );
    }
}
