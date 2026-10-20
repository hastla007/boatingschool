<?php

namespace App\Http\Controllers;

use App\Models\CourseDefinition;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Services\CourseProgressService;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
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

        $validated = $request->validate($this->rulesFor($template));

        PraxisFormSubmission::create([
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $user->id,
            'praxis_form_template_id' => $template->id,
            'data' => $validated,
        ]);

        return redirect()->route('praxis-pruefung.index', $course);
    }

    /** @return array<string, array<int, string>> */
    private function rulesFor(PraxisFormTemplate $template): array
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
                default => [...$base, 'string', 'max:255'],
            };
        }

        return $rules;
    }
}
