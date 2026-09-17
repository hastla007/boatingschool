<?php

namespace App\Http\Controllers;

use App\Models\ContentAnswer;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamSession;
use App\Models\ExamSessionQuestion;
use App\Models\Favorite;
use App\Models\Progress;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prüfungsengine laut api_spec.md: Rule-Set-Snapshot, keine Sofortauflösung,
 * serverseitige Zeit-/Bestehensregeln, unveränderliches Ergebnis nach Abschluss.
 */
class ExamController extends Controller
{
    public function intro(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();
        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403);

        $papers = $course->examPapers;

        if ($papers->isNotEmpty()) {
            return $this->papersOverview($tenant, $user, $course, $papers);
        }

        $ruleSet = $course->activeExamRuleSet();

        return view('exam.intro', [
            'course' => $course,
            'ruleSet' => $ruleSet,
            'hasNavigationTasks' => $course->navigationTasks()->exists(),
        ]);
    }

    /** @param Collection<int, ExamPaper> $papers */
    private function papersOverview(Tenant $tenant, User $user, CourseDefinition $course, Collection $papers): View
    {
        $paperIds = $papers->pluck('id');

        $latestEvaluatedSessions = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->whereIn('paper_id', $paperIds)
            ->where('status', 'evaluated')
            ->orderByDesc('submitted_at')
            ->get()
            ->unique('paper_id')
            ->keyBy('paper_id');

        $paperStats = $papers->map(function ($paper) use ($latestEvaluatedSessions) {
            $session = $latestEvaluatedSessions->get($paper->id);
            $total = $paper->paperQuestions()->count();

            return [
                'paper' => $paper,
                'attempted' => (bool) $session,
                'percent' => $session ? (int) round($session->score * 100) : 0,
                'correct' => $session ? $session->questions()->where('correct', true)->count() : 0,
                'total' => $total,
            ];
        });

        $attemptedStats = $paperStats->filter(fn ($s) => $s['attempted']);
        $overallPercent = $attemptedStats->isNotEmpty() ? (int) round($attemptedStats->avg('percent')) : 0;

        $favoriteIds = Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)->pluck('question_id');
        $favoritesMastered = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->whereIn('question_id', $favoriteIds)->where('learning_state', 'gefestigt')->count();

        return view('exam.papers', [
            'course' => $course,
            'paperStats' => $paperStats,
            'overallPercent' => $overallPercent,
            'favoritesTotal' => $favoriteIds->count(),
            'favoritesMastered' => $favoritesMastered,
            'hasNavigationTasks' => $course->navigationTasks()->exists(),
        ]);
    }

    public function startPaper(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, ExamPaper $paper): Response
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();
        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403);
        abort_unless($paper->course_id === $course->id, 404);

        $ruleSet = $course->activeExamRuleSet();
        abort_unless($ruleSet && $ruleSet->isVerified(), 409, 'Für diesen Kurs liegt noch kein fachlich freigegebenes Prüfungsregelwerk vor.');

        $running = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('paper_id', $paper->id)->where('status', 'running')->first();

        if ($running) {
            return redirect()->route('exam.show', $running);
        }

        $session = DB::transaction(function () use ($tenant, $user, $course, $ruleSet, $paper) {
            $session = ExamSession::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'course_id' => $course->id,
                'rule_set_id' => $ruleSet->id,
                'paper_id' => $paper->id,
                'status' => 'running',
                'started_at' => now(),
            ]);

            foreach ($paper->paperQuestions as $paperQuestion) {
                $revision = $paperQuestion->question->publishedRevision();
                abort_if(! $revision, 404, 'Für eine Frage dieses Bogens liegt keine veröffentlichte Fassung vor.');

                ExamSessionQuestion::create([
                    'exam_session_id' => $session->id,
                    'question_id' => $paperQuestion->question_id,
                    'revision_id' => $revision->id,
                    'position' => $paperQuestion->position,
                ]);
            }

            return $session;
        });

        return redirect()->route('exam.show', $session);
    }

    public function start(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): Response
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();
        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403);

        $ruleSet = $course->activeExamRuleSet();
        abort_unless($ruleSet && $ruleSet->isVerified(), 409, 'Für diesen Kurs liegt noch kein fachlich freigegebenes Prüfungsregelwerk vor.');

        $session = DB::transaction(function () use ($tenant, $user, $course, $ruleSet) {
            $session = ExamSession::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'course_id' => $course->id,
                'rule_set_id' => $ruleSet->id,
                'status' => 'running',
                'started_at' => now(),
            ]);

            $position = 1;
            foreach ($ruleSet->blueprints as $blueprint) {
                $questions = $blueprint->module->questions()
                    ->whereHas('revisions', fn ($q) => $q->where('editorial_status', 'published'))
                    ->inRandomOrder()
                    ->take($blueprint->question_count)
                    ->get();

                foreach ($questions as $question) {
                    $revision = $question->publishedRevision();

                    ExamSessionQuestion::create([
                        'exam_session_id' => $session->id,
                        'question_id' => $question->id,
                        'revision_id' => $revision->id,
                        'position' => $position++,
                    ]);
                }
            }

            return $session;
        });

        return redirect()->route('exam.show', $session);
    }

    public function show(Request $request, ExamSession $examSession): View|Response
    {
        $this->authorizeSession($request, $examSession);

        if ($examSession->status === 'evaluated') {
            return redirect()->route('exam.result', $examSession);
        }

        if ($this->timeExpired($examSession)) {
            return $this->finish($request, $examSession);
        }

        $current = $examSession->questions()->whereNull('answered_at')->orderBy('position')->with('revision.answers')->first();

        if (! $current) {
            return $this->finish($request, $examSession);
        }

        $total = $examSession->questions()->count();
        $answered = $examSession->questions()->whereNotNull('answered_at')->count();
        $remainingSeconds = $examSession->ruleSet->time_limit_seconds
            ? max(0, $examSession->ruleSet->time_limit_seconds - $examSession->started_at->diffInSeconds(now()))
            : null;

        return view('exam.question', [
            'examSession' => $examSession,
            'current' => $current,
            'total' => $total,
            'answered' => $answered,
            'remainingSeconds' => $remainingSeconds,
        ]);
    }

    public function answer(Request $request, ExamSession $examSession): Response
    {
        $this->authorizeSession($request, $examSession);
        abort_if($examSession->status !== 'running', 409);

        $validated = $request->validate([
            'position' => ['required', 'integer'],
            'answer_id' => ['required', 'uuid'],
        ]);

        $sessionQuestion = $examSession->questions()->where('position', $validated['position'])->firstOrFail();

        $answer = ContentAnswer::where('id', $validated['answer_id'])
            ->where('revision_id', $sessionQuestion->revision_id)
            ->firstOrFail();

        // Keine Sofortauflösung: correct wird erst bei finish() befüllt.
        $sessionQuestion->update([
            'selected_answer_id' => $answer->id,
            'answered_at' => now(),
        ]);

        return redirect()->route('exam.show', $examSession);
    }

    public function finish(Request $request, ExamSession $examSession): Response
    {
        $this->authorizeSession($request, $examSession);

        if ($examSession->status === 'evaluated') {
            return redirect()->route('exam.result', $examSession);
        }

        DB::transaction(function () use ($examSession) {
            $questions = $examSession->questions()->with('revision.answers')->lockForUpdate()->get();
            $correctCount = 0;

            foreach ($questions as $sq) {
                $correctAnswerId = $sq->revision->correctAnswer()?->id;
                $isCorrect = $sq->selected_answer_id !== null && $sq->selected_answer_id === $correctAnswerId;
                $sq->update(['correct' => $isCorrect]);
                $correctCount += $isCorrect ? 1 : 0;
            }

            $total = max($questions->count(), 1);
            $score = round($correctCount / $total, 4);
            $minRatio = $examSession->ruleSet->passing_rule['min_correct_ratio'] ?? 0.75;

            $examSession->update([
                'status' => 'evaluated',
                'submitted_at' => now(),
                'score' => $score,
                'passed' => $score >= $minRatio,
            ]);
        });

        return redirect()->route('exam.result', $examSession);
    }

    public function result(Request $request, ExamSession $examSession): View
    {
        $this->authorizeSession($request, $examSession);
        abort_unless($examSession->status === 'evaluated', 409, 'Prüfung ist noch nicht abgeschlossen.');

        $questions = $examSession->questions()->with('revision.answers')->orderBy('position')->get();
        $wrong = $questions->where('correct', false);

        $topicBreakdown = $questions->groupBy(fn ($q) => $q->revision->topic ?? '—')
            ->map(fn ($rows) => [
                'total' => $rows->count(),
                'correct' => $rows->where('correct', true)->count(),
            ]);

        return view('exam.result', [
            'examSession' => $examSession,
            'questions' => $questions,
            'wrong' => $wrong,
            'topicBreakdown' => $topicBreakdown,
        ]);
    }

    private function authorizeSession(Request $request, ExamSession $examSession): void
    {
        abort_unless($examSession->user_id === $request->user()->id, 403);
    }

    private function timeExpired(ExamSession $examSession): bool
    {
        $limit = $examSession->ruleSet->time_limit_seconds;

        return $limit && $examSession->started_at->diffInSeconds(now()) > $limit;
    }
}
