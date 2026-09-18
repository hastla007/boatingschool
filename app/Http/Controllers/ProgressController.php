<?php

namespace App\Http\Controllers;

use App\Models\ExamSession;
use App\Models\Progress;
use App\Services\CourseProgressService;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProgressController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseProgressService $courseProgress): View
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

        // Dieselbe Kategorie-Aufschlüsselung wie im Smart-Learning (Modul ->
        // smartmodus_kategorie, "gefestigt"-Anteil), über alle gebuchten
        // Kurse hinweg zusammengeführt, damit die Fortschrittsseite echte
        // Smart-Learning-Daten statt einer eigenen Trefferquoten-Berechnung zeigt.
        $byTopic = $courses
            ->flatMap(fn ($course) => $courseProgress->moduleKategorieBreakdown($course, $tenant, $user)
                ->flatMap(fn ($group) => $group['topics']->map(fn ($topic) => $topic + [
                    'course' => $course,
                    'module' => $group['module'],
                ])))
            ->groupBy('topic')
            ->map(function ($entries) {
                $total = $entries->sum('total');
                $topicMastered = $entries->sum('mastered');
                $reference = $entries->sortByDesc('total')->first();

                return [
                    'total' => $total,
                    'mastered' => $topicMastered,
                    'percent' => $total > 0 ? (int) round($topicMastered / $total * 100) : 0,
                    'course' => $reference['course'],
                    'module' => $reference['module'],
                ];
            })
            ->sortBy('percent');

        $examResults = ExamSession::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'evaluated')
            ->with('course', 'paper', 'questions')
            ->orderByDesc('submitted_at')
            ->get();

        // Zufällig generierte Prüfungen haben keinen echten Bogen, sollen
        // aber genauso als "Prüfungsbogen Nr. X" beschriftet werden --
        // fortlaufend nummeriert pro Kurs in der Reihenfolge ihrer Abgabe.
        $randomOrdinals = [];
        foreach ($examResults->where('paper_id', null)->groupBy('course_id') as $sessionsForCourse) {
            $sessionsForCourse->sortBy('submitted_at')->values()->each(function ($session, $index) use (&$randomOrdinals) {
                $randomOrdinals[$session->id] = $index + 1;
            });
        }

        $examResults->each(function (ExamSession $session) use ($randomOrdinals) {
            $session->displayLabel = 'Prüfungsbogen Nr. '.($session->paper->paper_number ?? $randomOrdinals[$session->id]);
            $session->wrongQuestionIds = $session->questions->where('correct', false)->pluck('question_id')->values();
        });

        return view('learning.progress', [
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
