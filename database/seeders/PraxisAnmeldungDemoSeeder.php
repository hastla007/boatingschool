<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\Tenant;
use App\Models\TenantCourseLocation;
use App\Models\User;
use App\Services\PraxisExamAppointmentService;
use Illuminate\Database\Seeder;

/**
 * Demo-Bestückung für eingegangene Praxisformular-Anmeldungen (siehe
 * PraxisFormSubmission, PraxisFormSubmissionController::store()): Max
 * Mustermann meldet sich für zwei Kurse an, damit der Tab
 * "Praxis-Anmeldungen" im Bootsschul-Admin nicht leer bleibt und beide
 * Terminstadien sichtbar sind -- "SBF See & Binnen" mit abgelehntem
 * erstem Vorschlag, fixiertem Prüfungstermin und einer offenen
 * zusätzlichen Praxisstunde; "Ergänzung See → Binnen" mit einem noch
 * unbeantworteten Terminvorschlag. Die "data"-Struktur folgt exakt dem,
 * was der echte Absende-Weg (inkl. Auflösung des Ort-Feldes auf
 * Praxis-/Prüfungsadresse) erzeugen würde, und die Terminrunden laufen
 * über den echten PraxisExamAppointmentService (nicht per Rohdaten).
 */
class PraxisAnmeldungDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'mueller')->first();
        $user = User::where('email', 'max@bootsschule-mueller.de')->first();
        $admin = User::where('email', 'admin@bootsschule-mueller.de')->first();
        $location = TenantCourseLocation::where('tenant_id', $tenant?->id)->where('praxis_ort', 'Hauptstandort Hamburg')->first();

        if (! $tenant || ! $user || ! $admin || ! $location) {
            return;
        }

        $this->seedSbfSeeBin($tenant, $user, $admin, $location);
        $this->seedErgaenzungSeeToBin($tenant, $user, $admin, $location);
    }

    private function seedSbfSeeBin(Tenant $tenant, User $user, User $admin, TenantCourseLocation $location): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE-BIN')->whereNull('tenant_id')->first();
        $template = PraxisFormTemplate::where('title', 'Praxis & Prüfung Motorboot')->first();

        if (! $course || ! $template) {
            return;
        }

        $submission = PraxisFormSubmission::firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'course_id' => $course->id],
            [
                'praxis_form_template_id' => $template->id,
                'data' => $this->praxisData($location, [
                    'wunschdatum' => now()->addWeeks(6)->toDateString(),
                    'tauglichkeit_vorhanden' => true,
                ]),
            ]
        );

        if ($submission->examAppointments()->exists()) {
            return;
        }

        $appointments = app(PraxisExamAppointmentService::class);

        // $submission wird vor jedem propose() frisch geladen: der Service
        // liest die Rundennummer über die (lazy geladene) Relation
        // examAppointments, die Eloquent nach dem ersten Zugriff auf dem
        // PHP-Objekt zwischenspeichert -- ohne reload() würde jeder weitere
        // Aufruf auf demselben Objekt die inzwischen veraltete, leere
        // Zwischenspeicherung sehen und wieder bei Runde 1 anfangen. In der
        // echten Anwendung passiert das nie, weil Vorschlag und Rückmeldung
        // über getrennte HTTP-Requests (und damit frische Objekte) laufen.

        // Runde 1: erster Prüfungstermin-Vorschlag, vom Teilnehmer abgelehnt.
        $firstProposal = $appointments->propose($submission, $admin, now()->addWeeks(3)->setTime(9, 0), slot: 0)->refresh();
        $appointments->respond($firstProposal, 'declined', 'Termin passt leider nicht, bin im Urlaub.');

        // Runde 2: neuer Vorschlag, vom Teilnehmer angenommen -> fixiert.
        $submission = $submission->fresh();
        $secondProposal = $appointments->propose($submission, $admin, now()->addWeeks(4)->setTime(9, 0), slot: 0)->refresh();
        $appointments->respond($secondProposal, 'accepted', null);

        // Zusätzliche Praxisstunde, noch ohne Rückmeldung des Teilnehmers.
        $submission = $submission->fresh();
        $appointments->propose(
            $submission,
            $admin,
            now()->addWeeks(4)->addDays(3)->setTime(16, 0),
            now()->addWeeks(4)->addDays(3)->setTime(18, 0),
            label: 'Kleine Praxisstunde (2h)',
        );
    }

    private function seedErgaenzungSeeToBin(Tenant $tenant, User $user, User $admin, TenantCourseLocation $location): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'ADD-SEE-TO-BIN')->whereNull('tenant_id')->first();
        $template = PraxisFormTemplate::where('title', 'Praxis & Prüfung Ergänzungskurs')->first();

        if (! $course || ! $template) {
            return;
        }

        $submission = PraxisFormSubmission::firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'course_id' => $course->id],
            [
                'praxis_form_template_id' => $template->id,
                'data' => $this->praxisData($location, [
                    'wunschdatum' => now()->addWeeks(5)->toDateString(),
                    'tauglichkeit_vorhanden' => false,
                ]),
            ]
        );

        if ($submission->examAppointments()->exists()) {
            return;
        }

        // Noch unbeantworteter erster Vorschlag -- zeigt den "vorschlagen"-
        // Zustand im Gegensatz zum fixierten Termin bei SBF See & Binnen.
        app(PraxisExamAppointmentService::class)
            ->propose($submission, $admin, now()->addWeeks(5)->setTime(10, 0), slot: 0);
    }

    /** @return array<string, mixed> */
    private function praxisData(TenantCourseLocation $location, array $overrides): array
    {
        return array_merge([
            'ort' => $location->praxis_ort,
            'ort_adresse' => $location->praxisAdresse(),
            'ort_pruefungsort' => $location->pruefung_ort,
            'ort_pruefungsort_adresse' => $location->pruefungAdresse(),
            'geburtsort' => 'Hamburg',
            'nationalitaet' => 'Deutsch',
            'telefonnummer' => '+49 170 1234567',
            'geschlecht' => 'Männlich',
            'vorlaeufiger_fuehrerschein' => false,
        ], $overrides);
    }
}
