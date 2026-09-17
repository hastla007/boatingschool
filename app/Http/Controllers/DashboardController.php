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
        $totalAttempts = $progressStats->sum('attempt_count');
        $totalCorrect = $progressStats->sum('correct_count');
        $totalIncorrect = $progressStats->sum('incorrect_count');
        $dueReviews = $progressStats->where('next_review_at', '<=', now())->where('current_streak', '>', 0)->count();

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
            ];
        });

        return view('dashboard', [
            'courses' => $courseSummaries,
            'totalAttempts' => $totalAttempts,
            'totalCorrect' => $totalCorrect,
            'totalIncorrect' => $totalIncorrect,
            'dueReviews' => $dueReviews,
        ]);
    }
}
