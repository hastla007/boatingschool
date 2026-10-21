<?php

namespace App\Http\Controllers;

use App\Models\Progress;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenantContext, EntitlementService $entitlements): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        $courses = $entitlements->activeCourses($tenant, $user);

        $progressStats = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get();

        // Jeder gebuchte Kurs bekommt auf dem Dashboard eine eigene Karte
        // (siehe dashboard.blade.php) -- daher hier je Kurs die vollen
        // Statistiken statt nur des einen "aktivsten" Kurses, den es vor
        // Einführung mehrerer Kurskarten gab.
        $courseSummaries = $courses->map(function ($course) use ($progressStats) {
            $questionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique();
            $courseProgress = $progressStats->whereIn('question_id', $questionIds);
            $mastered = $courseProgress->where('learning_state', 'gefestigt')->count();
            $total = max($questionIds->count(), 1);

            return [
                'course' => $course,
                'total_questions' => $questionIds->count(),
                'answered' => $courseProgress->where('attempt_count', '>', 0)->count(),
                'mastered' => $mastered,
                'percent' => (int) round(($mastered / $total) * 100),
                'correct' => $courseProgress->sum('correct_count'),
                'incorrect' => $courseProgress->sum('incorrect_count'),
                'attempts' => $courseProgress->sum('attempt_count'),
            ];
        });

        return view('dashboard', [
            'courses' => $courseSummaries,
        ]);
    }
}
