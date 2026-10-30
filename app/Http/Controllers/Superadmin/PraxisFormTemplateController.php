<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\CourseDefinition;
use App\Models\PraxisFormTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Formulare für die Anmeldung zu "Praxis & Prüfung" (siehe
 * PraxisFormTemplate): amtlich/kursgebunden statt bootsschulspezifisch,
 * daher im Superadmin statt im Bootsschul-Admin gepflegt. Pro Kurs darf
 * laut Fachkonzept höchstens ein aktives Formular zuständig sein -- ein
 * Formular kann aber mehreren Kursen gleichzeitig zugewiesen werden
 * (course_ids), analog zum Kursempfehlungs-Banner.
 */
class PraxisFormTemplateController extends Controller
{
    public function index(): View
    {
        $templates = PraxisFormTemplate::orderBy('title')->get();
        $courses = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')
            ->where('site_enabled', true)->orderBy('name')->get()->keyBy('id');

        return view('superadmin.praxis-form-templates.index', ['templates' => $templates, 'courses' => $courses]);
    }

    public function create(): View
    {
        $courses = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')
            ->where('site_enabled', true)->orderBy('name')->get();

        return view('superadmin.praxis-form-templates.create', ['courses' => $courses, 'assignedCourseIds' => $this->assignedCourseIds()]);
    }

    public function store(Request $request): Response
    {
        $validated = $this->validateTemplate($request);

        // Bug-Review (AA13: Deaktiviertes Praxis-Formular wird beim
        // Bearbeiten aktiv): validateTemplate() setzte active früher
        // selbst auf true -- geteilt mit update(), überschrieb das dort
        // den tatsächlichen (ggf. deaktivierten) Status bei jeder
        // Bearbeitung. Nur ein wirklich NEUES Formular startet aktiv;
        // update() lässt active jetzt unverändert -- dafür ist allein
        // toggleActive() da.
        $validated['active'] = true;

        PraxisFormTemplate::create($validated);

        return redirect()->route('superadmin.praxis-form-templates.index')->with('status', 'Praxisformular angelegt.');
    }

    public function edit(PraxisFormTemplate $praxisFormTemplate): View
    {
        $courses = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')
            ->where('site_enabled', true)->orderBy('name')->get();

        return view('superadmin.praxis-form-templates.edit', [
            'template' => $praxisFormTemplate,
            'courses' => $courses,
            'assignedCourseIds' => $this->assignedCourseIds($praxisFormTemplate->id),
        ]);
    }

    public function update(Request $request, PraxisFormTemplate $praxisFormTemplate): Response
    {
        $validated = $this->validateTemplate($request, $praxisFormTemplate->id);

        $praxisFormTemplate->update($validated);

        return redirect()->route('superadmin.praxis-form-templates.index')->with('status', 'Praxisformular aktualisiert.');
    }

    public function toggleActive(PraxisFormTemplate $praxisFormTemplate): Response
    {
        $activating = ! $praxisFormTemplate->active;

        // Bug-Review F3: store()/update() lehnen über conflictingCourses()
        // ab, wenn ein Kurs bereits einem anderen AKTIVEN Formular
        // zugewiesen ist -- dieser Umschalter prüfte das bisher gar nicht.
        // Ein Formular A (Kurs X) ließ sich deaktivieren, Formular B
        // (ebenfalls Kurs X) anlegen/aktivieren, und A anschließend über
        // genau diesen Umschalter wieder aktivieren -- ohne jede Prüfung
        // waren damit zwei aktive Formulare für denselben Kurs gleichzeitig
        // zuständig.
        if ($activating) {
            $conflicts = $this->conflictingCourses($praxisFormTemplate->course_ids ?? [], $praxisFormTemplate->id);

            if ($conflicts->isNotEmpty()) {
                return back()->withErrors([
                    'template' => 'Dieses Formular kann nicht aktiviert werden -- diese Kurse sind bereits einem anderen aktiven Formular zugewiesen: '.$conflicts->implode(', ').'.',
                ]);
            }
        }

        $praxisFormTemplate->update(['active' => $activating]);

        return back()->with('status', $activating ? 'Praxisformular aktiviert.' : 'Praxisformular deaktiviert.');
    }

    public function destroy(PraxisFormTemplate $praxisFormTemplate): Response
    {
        // Bug-Review N10: praxis_form_submission.praxis_form_template_id
        // hat keinen ON DELETE CASCADE/SET NULL -- ein Löschversuch bei
        // bereits bestehenden Anmeldungen verletzte den FK-Constraint und
        // ergab einen HTTP 500 statt einer verständlichen Fehlermeldung.
        // Anmeldungen sind echte, abgeschlossene Praxis-Prüfungs-
        // Registrierungen und dürfen beim Löschen der Formularvorlage
        // nicht verloren gehen -- analog zu Superadmin\TipCategoryController
        // ::destroy().
        if ($praxisFormTemplate->submissions()->exists()) {
            return back()->withErrors(['template' => 'Dieses Formular wird bereits von Anmeldungen verwendet und kann nicht gelöscht werden.']);
        }

        $praxisFormTemplate->delete();

        return redirect()->route('superadmin.praxis-form-templates.index')->with('status', 'Praxisformular gelöscht.');
    }

    /** @return array<string, mixed> */
    private function validateTemplate(Request $request, ?string $ignoreTemplateId = null): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'min_progress_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'requires_tauglichkeit' => ['nullable', 'boolean'],
            'course_ids' => ['nullable', 'array'],
            'course_ids.*' => ['uuid', 'exists:course_definition,id'],
            'fields' => ['nullable', 'array'],
            // Bug-Review F3: der Key kommt, wenn vorhanden, aus einem
            // versteckten Formularfeld (siehe _form.blade.php) -- die
            // Zeichenklasse entspricht genau dem, was parseFields() selbst
            // per Str::slug(..., '_') erzeugt.
            'fields.*.key' => ['nullable', 'string', 'regex:/^[a-z0-9_]+$/'],
            'fields.*.label' => ['required_with:fields', 'string', 'max:255'],
            'fields.*.type' => ['required_with:fields', 'in:text,textarea,date,select,radio,checkbox,location'],
            'fields.*.required' => ['nullable', 'boolean'],
            'fields.*.options' => ['nullable', 'string'],
            'fields.*.help_text' => ['nullable', 'string', 'max:500'],
        ]);

        $courseIds = array_values($validated['course_ids'] ?? []);
        $conflicts = $this->conflictingCourses($courseIds, $ignoreTemplateId);

        if ($conflicts->isNotEmpty()) {
            throw ValidationException::withMessages([
                'course_ids' => 'Diese Kurse sind bereits einem anderen aktiven Formular zugewiesen: '.$conflicts->implode(', ').'.',
            ]);
        }

        return [
            'title' => $validated['title'],
            'min_progress_percent' => (int) $validated['min_progress_percent'],
            'requires_tauglichkeit' => (bool) ($validated['requires_tauglichkeit'] ?? false),
            'course_ids' => $courseIds,
            'fields' => $this->parseFields($validated['fields'] ?? []),
        ];
    }

    /** @return Collection<int, string> Namen der bereits belegten Kurse. */
    private function conflictingCourses(array $courseIds, ?string $ignoreTemplateId): Collection
    {
        if (empty($courseIds)) {
            return collect();
        }

        $taken = collect();

        PraxisFormTemplate::where('active', true)
            ->when($ignoreTemplateId, fn ($q) => $q->where('id', '!=', $ignoreTemplateId))
            ->get()
            ->each(function (PraxisFormTemplate $other) use ($courseIds, &$taken) {
                $taken = $taken->merge(array_intersect($courseIds, $other->course_ids ?? []));
            });

        if ($taken->isEmpty()) {
            return collect();
        }

        return CourseDefinition::withoutGlobalScopes()->whereIn('id', $taken->unique())->pluck('name');
    }

    /** @return array<int, array<string, mixed>> */
    private function parseFields(array $rawFields): array
    {
        $used = [];

        return collect($rawFields)
            ->filter(fn ($f) => trim((string) ($f['label'] ?? '')) !== '')
            ->values()
            ->map(function ($f) use (&$used) {
                // Bug-Review F3: ein bereits bestehender Key (aus dem
                // versteckten Formularfeld, siehe _form.blade.php) wird
                // wiederverwendet statt bei JEDEM Speichern neu aus dem
                // aktuellen Label abgeleitet zu werden -- sonst verlieren
                // bestehende Anmeldungen (PraxisFormSubmission::data ist
                // nach genau diesem Key geschlüsselt) ihre Antworten aus
                // der Sicht, sobald sich nur die Beschriftung ändert (auch
                // bei einer reinen Tippfehlerkorrektur). Nur ein wirklich
                // NEUES Feld (ohne mitgeschickten Key) bekommt einen frisch
                // aus dem Label abgeleiteten Key.
                $key = trim((string) ($f['key'] ?? ''));
                if ($key === '') {
                    $key = Str::slug($f['label'], '_');
                    $key = $key !== '' ? $key : 'feld';
                }
                $base = $key;
                $i = 2;
                while (in_array($key, $used, true)) {
                    $key = $base.'_'.$i;
                    $i++;
                }
                $used[] = $key;

                $options = array_values(array_filter(array_map('trim', explode("\n", $f['options'] ?? ''))));

                return [
                    'key' => $key,
                    'label' => trim($f['label']),
                    'type' => $f['type'],
                    'required' => (bool) ($f['required'] ?? false),
                    'options' => in_array($f['type'], ['select', 'radio'], true) ? $options : [],
                    'help_text' => trim((string) ($f['help_text'] ?? '')) ?: null,
                ];
            })
            ->all();
    }

    /** Kurs-IDs, die bereits einem (anderen) aktiven Formular zugewiesen sind. */
    private function assignedCourseIds(?string $ignoreTemplateId = null): array
    {
        return PraxisFormTemplate::where('active', true)
            ->when($ignoreTemplateId, fn ($q) => $q->where('id', '!=', $ignoreTemplateId))
            ->get()
            ->flatMap(fn (PraxisFormTemplate $t) => $t->course_ids ?? [])
            ->unique()
            ->values()
            ->all();
    }
}
