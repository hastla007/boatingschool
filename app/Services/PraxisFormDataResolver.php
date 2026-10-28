<?php

namespace App\Services;

use App\Models\CourseDefinition;
use App\Models\PraxisFormTemplate;
use App\Models\TenantCourseLocation;
use Illuminate\Support\Collection;

/**
 * Validierungsregeln und Datenauflösung für ein ausgefülltes Praxisformular
 * (siehe PraxisFormTemplate/PraxisFormSubmission) -- ursprünglich nur in
 * PraxisFormSubmissionController (Lerner-Absenden), seit Bug-Review F6 auch
 * von Admin\PraxisFormSubmissionController::update() genutzt (Korrektur
 * einer bestehenden Anmeldung durch die Bootsschule).
 */
class PraxisFormDataResolver
{
    /** @return array<string, array<int, string>> */
    public function rulesFor(PraxisFormTemplate $template, Collection $locations): array
    {
        $rules = [];

        foreach ($template->fields as $field) {
            $key = $field['key'];
            $required = ! empty($field['required']);
            $base = $required ? ['required'] : ['nullable'];

            $rules[$key] = match ($field['type']) {
                'date' => [...$base, 'date'],
                'checkbox' => $required ? ['required', 'accepted'] : ['nullable', 'boolean'],
                'select', 'radio' => [...$base, 'in:'.implode(',', $field['options'] ?? [])],
                'textarea' => [...$base, 'string', 'max:2000'],
                // Ohne hinterlegte Orte (siehe TenantCourseLocation) gibt es
                // nichts auszuwählen -- das Feld entfällt dann serverseitig
                // genauso wie in der Ansicht.
                'location' => $locations->isEmpty() ? ['nullable'] : [...$base, 'uuid', 'in:'.$locations->pluck('id')->implode(',')],
                default => [...$base, 'string', 'max:255'],
            };
        }

        return $rules;
    }

    /**
     * "location"-Felder enthalten nach der Validierung nur die UUID der
     * gewählten TenantCourseLocation -- hier wird daraus der lesbare
     * Praxisort (unter dem ursprünglichen Feld-Key) und der dazu fix
     * gekoppelte Prüfungsort (unter "<key>_pruefungsort") für die
     * Speicherung aufgelöst.
     *
     * @param  Collection<int, TenantCourseLocation>  $locations
     * @param  array<string, mixed>  $validated
     * @param  Collection<int, CourseDefinition>  $allCourses
     * @return array<string, mixed>
     */
    public function resolveLocationFields(PraxisFormTemplate $template, Collection $locations, array $validated, Collection $allCourses): array
    {
        $data = $validated;

        foreach ($template->fields as $field) {
            if ($field['type'] !== 'location' || empty($validated[$field['key']])) {
                continue;
            }

            $location = $locations->firstWhere('id', $validated[$field['key']]);

            if ($location) {
                // Bug-Review F6: der ursprünglich gewählte Ort wird hier
                // durch seinen lesbaren Namen ersetzt -- die UUID bleibt
                // zusätzlich erhalten, damit Admin\PraxisFormSubmission
                // Controller::edit() das "Ort"-Auswahlfeld beim Bearbeiten
                // wieder vorbelegen kann, ohne über den (ggf. mehrfach
                // vorkommenden) Namen zurücksuchen zu müssen.
                $data[$field['key'].'_location_id'] = $location->id;
                $data[$field['key']] = $location->praxis_ort;
                $data[$field['key'].'_adresse'] = $location->praxisAdresse();
                $data[$field['key'].'_pruefungsort'] = $location->pruefung_ort;
                $data[$field['key'].'_pruefungsort_adresse'] = $location->pruefungAdresse();

                if ($location->pruefungsausschuss) {
                    $data[$field['key'].'_pruefungsausschuss'] = $location->pruefungsausschuss->label();
                    $data[$field['key'].'_pruefungsausschuss_adresse'] = $location->pruefungsausschuss->adresse();

                    $kurse = collect($location->pruefungsausschuss->course_ids ?? [])
                        ->map(fn ($id) => $allCourses->firstWhere('id', $id)?->name)
                        ->filter()
                        ->values();

                    if ($kurse->isNotEmpty()) {
                        $data[$field['key'].'_pruefungsausschuss_kurse'] = $kurse->all();
                    }
                }

                if ($location->hasTheorieOrt()) {
                    $data[$field['key'].'_theorieort'] = $location->theorie_ort;
                    $data[$field['key'].'_theorieort_adresse'] = $location->theorieAdresse();
                }
            }
        }

        return $data;
    }

    /** Globaler Kurskatalog zur Auflösung der course_ids eines Prüfungsausschusses in lesbare Namen. */
    public function allCourses(): Collection
    {
        return CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->get(['id', 'name']);
    }
}
