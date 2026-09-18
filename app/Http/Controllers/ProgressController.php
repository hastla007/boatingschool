<?php

namespace App\Http\Controllers;

use App\Models\CourseDefinition;
use App\Models\ExamSession;
use App\Models\Progress;
use App\Services\CourseProgressService;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProgressController extends Controller
{
    public function show(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseProgressService $courseProgress, CourseDefinition $course): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403, 'Für diesen Kurs liegt kein aktives Entitlement vor.');

        $course->loadMissing('modules.questions');
        $questionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique();

        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->whereIn('question_id', $questionIds)->get();

        $totalQuestions = $questionIds->count();
        $totalAttempts = $progress->sum('attempt_count');
        $totalCorrect = $progress->sum('correct_count');
        $overallAccuracy = $totalAttempts > 0 ? round($totalCorrect / $totalAttempts * 100) : 0;
        $mastered = $progress->where('learning_state', 'gefestigt')->count();

        // Dieselbe Kategorie-Aufschlüsselung wie im Smart-Learning (Modul ->
        // smartmodus_kategorie, "gefestigt"-Anteil) dieses Kurses, damit die
        // Fortschrittsseite echte Smart-Learning-Daten statt einer eigenen
        // Trefferquoten-Berechnung zeigt.
        $byTopic = $courseProgress->moduleKategorieBreakdown($course, $tenant, $user)
            ->flatMap(fn ($group) => $group['topics']->map(fn ($topic) => $topic + ['module' => $group['module']]))
            ->groupBy('topic')
            ->map(function ($entries) {
                $total = $entries->sum('total');
                $topicMastered = $entries->sum('mastered');
                $reference = $entries->sortByDesc('total')->first();

                return [
                    'total' => $total,
                    'mastered' => $topicMastered,
                    'percent' => $total > 0 ? (int) round($topicMastered / $total * 100) : 0,
                    'module' => $reference['module'],
                ];
            })
            ->sortBy('percent');

        $examResults = ExamSession::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', 'evaluated')
            ->with('course', 'paper', 'questions')
            ->orderByDesc('submitted_at')
            ->get();

        // Zufällig generierte Prüfungen haben keinen echten Bogen, sollen
        // aber genauso als "Prüfungsbogen Nr. X" beschriftet werden --
        // fortlaufend nummeriert in der Reihenfolge ihrer Abgabe.
        $randomOrdinals = [];
        $examResults->where('paper_id', null)->sortBy('submitted_at')->values()->each(function ($session, $index) use (&$randomOrdinals) {
            $randomOrdinals[$session->id] = $index + 1;
        });

        $examResults->each(function (ExamSession $session) use ($randomOrdinals) {
            $session->displayLabel = 'Prüfungsbogen Nr. '.($session->paper->paper_number ?? $randomOrdinals[$session->id]);
            $session->wrongQuestionIds = $session->questions->where('correct', false)->pluck('question_id')->values();
        });

        return view('learning.progress', [
            'course' => $course,
            'totalQuestions' => $totalQuestions,
            'answered' => $progress->where('attempt_count', '>', 0)->count(),
            'mastered' => $mastered,
            'overallAccuracy' => $overallAccuracy,
            'dueReviews' => $progress->where('next_review_at', '<=', now())->where('current_streak', '>', 0)->count(),
            'byTopic' => $byTopic,
            'examResults' => $examResults,
        ]);
    }
}
