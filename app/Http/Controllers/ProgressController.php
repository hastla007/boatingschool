<?php

namespace App\Http\Controllers;

use App\Models\Progress;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProgressController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext, EntitlementService $entitlements): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        $courses = $entitlements->activeCourses($tenant, $user);
        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get();

        $totalQuestions = $courses->flatMap(fn ($c) => $c->modules)->flatMap(fn ($m) => $m->questions)->pluck('id')->unique()->count();
        $totalAttempts = $progress->sum('attempt_count');
        $totalCorrect = $progress->sum('correct_count');
        $overallAccuracy = $totalAttempts > 0 ? round($totalCorrect / $totalAttempts * 100) : 0;
        $mastered = $progress->where('learning_state', 'gefestigt')->count();

        $byTopic = $progress->filter(fn ($p) => $p->attempt_count > 0)
            ->groupBy(fn ($p) => $p->question?->publishedRevision()?->topic ?? '—')
            ->map(function ($rows) {
                $attempts = $rows->sum('attempt_count');
                $correct = $rows->sum('correct_count');

                return [
                    'attempts' => $attempts,
                    'accuracy' => $attempts > 0 ? round($correct / $attempts * 100) : 0,
                ];
            })
            ->sortBy('accuracy');

        return view('learning.progress', [
            'totalQuestions' => $totalQuestions,
            'answered' => $progress->where('attempt_count', '>', 0)->count(),
            'mastered' => $mastered,
            'overallAccuracy' => $overallAccuracy,
            'dueReviews' => $progress->where('next_review_at', '<=', now())->where('current_streak', '>', 0)->count(),
            'byTopic' => $byTopic,
        ]);
    }
}
