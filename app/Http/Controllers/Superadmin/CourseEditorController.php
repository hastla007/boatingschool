<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\CourseDefinition;
use App\Models\Module;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CourseEditorController extends Controller
{
    public function index(): View
    {
        $courses = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')
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
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'course_type' => ['required', 'string', 'max:60'],
            'status' => ['required', 'in:draft,published,archived'],
        ]);

        $course = CourseDefinition::withoutGlobalScopes()->create($validated + ['tenant_id' => null]);

        return redirect()->route('superadmin.courses.show', $course)->with('status', 'Kurs angelegt.');
    }

    public function show(CourseDefinition $course): View
    {
        $course->loadMissing(['modules' => fn ($q) => $q->withCount('questions')]);

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
