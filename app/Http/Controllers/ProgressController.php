<?php

namespace App\Http\Controllers;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamSession;
use App\Models\Progress;
use App\Services\CourseProgressService;
use App\Services\EntitlementService;
use App\Support\ComboExamGroups;
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
        // Bug-Review (Fortschritt zählt deaktivierte Fragen): eine vom
        // Superadmin deaktivierte Frage kann nie "gefestigt" werden
        // (siehe CourseProgressService::overallPercent, Bug-Review F7),
        // zählte hier aber weiter im Nenner mit -- diese eigene, von
        // CourseProgressService losgelöste Zählung hatte den F7-Fix nie
        // mitbekommen.
        // Bug-Review BB16: dieselbe Lücke bestand für valid_from/valid_to.
        $questionIds = ContentQuestion::currentlyValidIds($course->modules->flatMap(fn ($m) => $m->questions));

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
            ->with('course', 'paper', 'questions.revision')
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
            // Bug-Review (Prüfungshistorie im Kombikurs zeigt rohe Bogen-Nr.):
            // ein Bogen eines Kombikurses (z. B. SBF-SEE-BIN) trägt intern die
            // paper_number des gespiegelten Teilkurses samt Offset (z. B. 101
            // für den ersten Binnen-Bogen) -- exam/papers.blade.php löst das
            // bereits über ComboExamGroups::infoFor() in eine Teilkurs-
            // Anzeigenummer auf, diese Historie zeigte die rohe Zahl aber
            // unverändert an ("Prüfungsbogen Nr. 101" statt "SBF Binnen --
            // Prüfungsbogen Nr. 1").
            $comboInfo = $session->paper
                ? ComboExamGroups::infoFor($session->course->code, $session->paper->paper_number)
                : null;
            $displayNumber = $comboInfo['displayNumber'] ?? $session->paper->paper_number ?? $randomOrdinals[$session->id];
            $session->displayLabel = $comboInfo
                ? $comboInfo['label'].' – Prüfungsbogen Nr. '.$displayNumber
                : 'Prüfungsbogen Nr. '.$displayNumber;
            // Bug-Review B2: Collection::where() vergleicht standardmäßig lose
            // (null == false wäre wahr) -- whereStrict erzwingt ===, auch wenn
            // bei status=evaluated correct für jede Frage bereits gesetzt ist.
            $session->wrongQuestionIds = $session->questions->whereStrict('correct', false)->pluck('question_id')->values();

            // Bug-Review (FKN offene Fragen): siehe ExamController::result() --
            // dieselbe Selbsteinschätzung-Transparenz auch hier, wo die
            // eigene Prüfungs-Historie dauerhaft einsehbar bleibt.
            $openTextQuestions = $session->questions->where('revision.question_type', 'open_text');
            $session->openTextCount = $openTextQuestions->count();
            $session->openTextCorrect = $openTextQuestions->where('correct', true)->count();
        });

        return view('learning.progress', [
            'course' => $course,
            'totalQuestions' => $totalQuestions,
            'answered' => $progress->where('attempt_count', '>', 0)->count(),
            'mastered' => $mastered,
            'overallAccuracy' => $overallAccuracy,
            'byTopic' => $byTopic,
            'examResults' => $examResults,
        ]);
    }
}
