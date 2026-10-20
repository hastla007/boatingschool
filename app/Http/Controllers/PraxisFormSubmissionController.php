<?php

namespace App\Http\Controllers;

use App\Models\CourseDefinition;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\TenantCourseLocation;
use App\Services\CourseProgressService;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nimmt das ausgefüllte Praxisformular (siehe PraxisFormTemplate) entgegen
 * -- Validierungsregeln werden aus den Feld-Definitionen der Vorlage
 * abgeleitet, da jede Vorlage ihre eigenen Felder hat. Die Daten sind nach
 * dem Absenden rein lesend (kein Editieren über dieses Formular), sie
 * dienen der Bootsschule als Grundlage für die Anmeldung bei der
 * Prüfungskommission.
 */
class PraxisFormSubmissionController extends Controller
{
    public function store(
        Request $request,
        TenantContext $tenantContext,
        EntitlementService $entitlements,
        CourseProgressService $courseProgress,
        CourseDefinition $course,
    ): Response {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403, 'Für diesen Kurs liegt kein aktives Entitlement vor.');

        $template = PraxisFormTemplate::where('active', true)
            ->whereJsonContains('course_ids', $course->id)
            ->first();

        abort_unless($template, 404, 'Für diesen Kurs ist aktuell kein Praxisformular hinterlegt.');

        $percent = $courseProgress->overallPercent($course, $tenant, $user);
        abort_unless($percent >= $template->min_progress_percent, 403, "Für die Anmeldung werden mindestens {$template->min_progress_percent}% Kursfortschritt benötigt.");

        $existing = PraxisFormSubmission::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->exists();

        if ($existing) {
            return back()->with('status', 'Du hast dich für diesen Kurs bereits angemeldet.');
        }

        $locations = TenantCourseLocation::where('tenant_id', $tenant->id)
            ->whereJsonContains('course_ids', $course->id)
            ->get();

        $validated = $request->validate($this->rulesFor($template, $locations));
        $data = $this->resolveLocationFields($template, $locations, $validated);

        PraxisFormSubmission::create([
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $user->id,
            'praxis_form_template_id' => $template->id,
            'data' => $data,
        ]);

        return redirect()->route('praxis-pruefung.index', $course);
    }

    /**
     * @param  Collection<int, TenantCourseLocation>  $locations
     * @return array<string, array<int, string>>
     */
    private function rulesFor(PraxisFormTemplate $template, Collection $locations): array
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
     * @return array<string, mixed>
     */
    private function resolveLocationFields(PraxisFormTemplate $template, Collection $locations, array $validated): array
    {
        $data = $validated;

        foreach ($template->fields as $field) {
            if ($field['type'] !== 'location' || empty($validated[$field['key']])) {
                continue;
            }

            $location = $locations->firstWhere('id', $validated[$field['key']]);

            if ($location) {
                $data[$field['key']] = $location->praxis_ort;
                $data[$field['key'].'_pruefungsort'] = $location->pruefung_ort;
            }
        }

        return $data;
    }
}
