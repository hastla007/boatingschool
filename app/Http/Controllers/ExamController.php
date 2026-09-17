<?php

namespace App\Http\Controllers;

use App\Models\ContentAnswer;
use App\Models\CourseDefinition;
use App\Models\ExamSession;
use App\Models\ExamSessionQuestion;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
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
        abort_unless($entitlements->hasAccess($tenant, $request->user(), $course), 403);

        $ruleSet = $course->activeExamRuleSet();

        return view('exam.intro', ['course' => $course, 'ruleSet' => $ruleSet]);
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
