<?php

namespace App\Http\Controllers;

use App\Models\CourseDefinition;
use App\Models\Progress;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CourseController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext, EntitlementService $entitlements): View
    {
        $courses = $entitlements->activeCourses($tenantContext->tenant(), $request->user());

        return view('courses.index', ['courses' => $courses]);
    }

    public function show(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): View|Response
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        if (! $entitlements->hasAccess($tenant, $user, $course)) {
            abort(403, 'Für diesen Kurs liegt kein aktives Entitlement vor.');
        }

        $course->load('modules.questions.revisions');

        // Eloquent-Collection::only() arbeitet über den Primärschlüssel des
        // Models; Progress hat aber einen zusammengesetzten Schlüssel ohne
        // einzelne id-Spalte, daher hier bewusst whereIn() auf dem Attribut.
        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get();

        $modules = $course->modules->map(function ($module) use ($progress) {
            $questionIds = $module->questions->pluck('id');
            $moduleProgress = $progress->whereIn('question_id', $questionIds);
            $total = max($questionIds->count(), 1);
            $mastered = $moduleProgress->where('learning_state', 'gefestigt')->count();

            return [
                'module' => $module,
                'total' => $questionIds->count(),
                'mastered' => $mastered,
                'percent' => (int) round(($mastered / $total) * 100),
            ];
        });

        return view('courses.show', ['course' => $course, 'modules' => $modules]);
    }
}
