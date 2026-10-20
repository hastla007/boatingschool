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
        $praxisFormTemplate->update(['active' => ! $praxisFormTemplate->active]);

        return back()->with('status', $praxisFormTemplate->active ? 'Praxisformular aktiviert.' : 'Praxisformular deaktiviert.');
    }

    public function destroy(PraxisFormTemplate $praxisFormTemplate): Response
    {
        $praxisFormTemplate->delete();

        return redirect()->route('superadmin.praxis-form-templates.index')->with('status', 'Praxisformular gelöscht.');
    }

    /** @return array<string, mixed> */
    private function validateTemplate(Request $request, ?string $ignoreTemplateId = null): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'min_progress_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'course_ids' => ['nullable', 'array'],
            'course_ids.*' => ['uuid', 'exists:course_definition,id'],
            'fields' => ['nullable', 'array'],
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
            'course_ids' => $courseIds,
            'fields' => $this->parseFields($validated['fields'] ?? []),
            'active' => true,
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
                $key = Str::slug($f['label'], '_');
                $key = $key !== '' ? $key : 'feld';
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
