<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\CourseDefinition;
use App\Models\Module;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CourseEditorController extends Controller
{
    /**
     * Bug-Review (Superadmin-Seite gesperrte Kurse ohne published-Filter):
     * die Liste zeigte bisher auch draft/archived-Kurse mit demselben
     * funktional aussehenden "Sitewide"-Schalter (aktiv/deaktiviert) wie
     * echte, angebotene Kurse -- ein solcher Kurs ist aber laut
     * EntitlementService::isOfferable() (Bug-Review B21: status !==
     * 'published' schließt Angebot/Zugriff IMMER aus, unabhängig von
     * site_enabled) für keinen Mandanten je erreichbar, der "Sitewide"-
     * Schalter also wirkungslos und irreführend. Die Liste zeigt jetzt nur
     * noch tatsächlich angebotene (published) Kurse, für die "gesperrt"/
     * "aktiv" überhaupt eine reale Bedeutung hat.
     */
    public function index(): View
    {
        $courses = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')
            ->where('status', 'published')
            ->withCount('modules')->orderBy('name')->get();

        return view('superadmin.courses.index', ['courses' => $courses]);
    }

    public function create(): View
    {
        return view('superadmin.courses.create');
    }

    public function store(Request $request): Response
    {
        $validated = $request->validate([
            // Bug-Review B21: der DB-Unique-Index liegt auf (tenant_id,
            // code) -- da tenant_id für globale Kurse NULL ist und
            // Postgres NULLs als paarweise verschieden behandelt, verhindert
            // das keine zwei globalen Kurse mit demselben Code. Diese Regel
            // prüft die Eindeutigkeit deshalb explizit innerhalb des
            // globalen Katalogs (tenant_id IS NULL).
            'code' => ['required', 'string', 'max:100', Rule::unique('course_definition', 'code')->whereNull('tenant_id')],
            'name' => ['required', 'string', 'max:255'],
            'course_type' => ['required', 'string', 'max:60'],
            'status' => ['required', 'in:draft,published,archived'],
        ]);

        $validated['site_enabled'] = $request->boolean('site_enabled', true);

        $course = CourseDefinition::withoutGlobalScopes()->create($validated + ['tenant_id' => null]);

        return redirect()->route('superadmin.courses.show', $course)->with('status', 'Kurs angelegt.');
    }

    public function show(CourseDefinition $course): View
    {
        $course->loadMissing(['modules' => fn ($q) => $q->withCount('questions')]);
        $course->loadMissing(['videoModules' => fn ($q) => $q->with(['lessons' => fn ($l) => $l->withCount('questions')])]);

        $availableModules = Module::whereNotIn('id', $course->modules->pluck('id'))->orderBy('name')->get();

        return view('superadmin.courses.show', [
            'course' => $course,
            'availableModules' => $availableModules,
        ]);
    }

    public function update(Request $request, CourseDefinition $course): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'course_type' => ['required', 'string', 'max:60'],
            'status' => ['required', 'in:draft,published,archived'],
        ]);

        $validated['site_enabled'] = $request->boolean('site_enabled');

        $course->update($validated);

        return back()->with('status', 'Kurs aktualisiert.');
    }

    public function attachModule(Request $request, CourseDefinition $course): Response
    {
        $validated = $request->validate([
            'module_id' => ['required', 'uuid', 'exists:module,id'],
        ]);

        $course->modules()->syncWithoutDetaching([
            $validated['module_id'] => ['sort_order' => $course->modules()->count() + 1, 'required' => true],
        ]);

        return back()->with('status', 'Modul zugeordnet.');
    }

    public function detachModule(CourseDefinition $course, Module $module): Response
    {
        $course->modules()->detach($module->id);

        return back()->with('status', 'Modul entfernt.');
    }
}
