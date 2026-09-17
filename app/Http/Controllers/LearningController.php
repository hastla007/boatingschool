<?php

namespace App\Http\Controllers;

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\Favorite;
use App\Models\Progress;
use App\Services\EntitlementService;
use App\Services\LearningService;
use App\Services\SmarttrainerService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LearningController extends Controller
{
    public function overview(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403, 'Für diesen Kurs liegt kein aktives Entitlement vor.');

        $course->load(['modules.questions.revisions' => function ($query) {
            $query->where('editorial_status', 'published')->orderByDesc('revision_no');
        }]);

        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get();

        $mastered = fn ($questionIds) => $progress->whereIn('question_id', $questionIds)->where('learning_state', 'gefestigt')->count();

        $moduleGroups = $course->modules->map(function ($module) use ($mastered) {
            $topics = $module->questions
                ->groupBy(function ($q) {
                    $revision = $q->revisions->first();

                    return $revision?->smartmodus_kategorie ?: ($revision?->topic ?: 'Sonstiges');
                })
                ->map(function ($questions, $topic) use ($mastered) {
                    $questionIds = $questions->pluck('id');
                    $total = $questionIds->count();
                    $topicMastered = $mastered($questionIds);

                    return [
                        'topic' => $topic,
                        'total' => $total,
                        'mastered' => $topicMastered,
                        'percent' => $total > 0 ? (int) round($topicMastered / $total * 100) : 0,
                    ];
                })
                ->sortByDesc('total')
                ->values();

            $questionIds = $module->questions->pluck('id');
            $total = $questionIds->count();
            $moduleMastered = $mastered($questionIds);

            return [
                'module' => $module,
                'topics' => $topics,
                'total' => $total,
                'mastered' => $moduleMastered,
                'percent' => $total > 0 ? (int) round($moduleMastered / $total * 100) : 0,
            ];
        })->values();

        $allQuestionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique();
        $overallPercent = $allQuestionIds->isNotEmpty() ? (int) round($mastered($allQuestionIds) / $allQuestionIds->count() * 100) : 0;

        $favoriteIds = Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)->pluck('question_id');

        return view('learning.overview', [
            'course' => $course,
            'overallPercent' => $overallPercent,
            'moduleGroups' => $moduleGroups,
            'favoritesTotal' => $favoriteIds->count(),
            'favoritesMastered' => $favoriteIds->isNotEmpty() ? $mastered($favoriteIds) : 0,
        ]);
    }

    public function show(
        Request $request,
        TenantContext $tenantContext,
        EntitlementService $entitlements,
        SmarttrainerService $smarttrainer,
        CourseDefinition $course,
    ): View {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403, 'Für diesen Kurs liegt kein aktives Entitlement vor.');

        $mode = $request->query('mode', 'smarttrainer');
        $topic = $request->query('topic');
        $moduleId = $request->query('module');

        if ($mode === 'favorites') {
            $favoriteIds = Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)->pluck('question_id');
            $questionId = $favoriteIds->isNotEmpty() ? $favoriteIds->random() : null;
            $next = $questionId ? ['question' => ContentQuestion::find($questionId), 'reason' => 'Favorit'] : null;
        } else {
            $next = $smarttrainer->nextQuestion($tenant, $user, $course, $mode, $topic, $moduleId);
        }

        if (! $next) {
            return view('learning.empty', ['course' => $course, 'mode' => $mode]);
        }

        $revision = $next['question']->publishedRevision();
        abort_if(! $revision, 404, 'Für diese Frage liegt keine veröffentlichte Fassung vor.');
        $revision->load('answers', 'media');

        $isFavorite = Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('question_id', $next['question']->id)->exists();

        return view('learning.question', [
            'course' => $course,
            'revision' => $revision,
            'reason' => $next['reason'],
            'mode' => $mode,
            'topic' => $topic,
            'moduleId' => $moduleId,
            'isFavorite' => $isFavorite,
            'startedAt' => now()->valueOf(),
            'answered' => false,
            'correct' => null,
            'selectedAnswerId' => null,
        ]);
    }

    public function storeAttempt(
        Request $request,
        TenantContext $tenantContext,
        EntitlementService $entitlements,
        LearningService $learningService,
        CourseDefinition $course,
    ) {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403);

        $validated = $request->validate([
            'revision_id' => ['required', 'uuid'],
            'answer_id' => ['nullable', 'uuid'],
            'mode' => ['required', 'string'],
            'response_time_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        $revision = ContentQuestionRevision::where('id', $validated['revision_id'])
            ->where('editorial_status', 'published')
            ->firstOrFail();

        // Sicherheitsregel laut api_spec.md: question_id/revision_id müssen zu
        // Content gehören, der für den Kurs des Nutzers tatsächlich freigegeben ist.
        $courseQuestionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id');
        abort_unless($courseQuestionIds->contains($revision->question_id), 403, 'Frage gehört nicht zum berechtigten Kurs.');

        $selectedAnswer = null;
        if ($validated['answer_id'] ?? null) {
            $selectedAnswer = ContentAnswer::where('id', $validated['answer_id'])
                ->where('revision_id', $revision->id)
                ->firstOrFail();
        }

        $attempt = $learningService->recordAttempt(
            $tenant, $user, $revision, $selectedAnswer, $validated['mode'], $course, $validated['response_time_ms'] ?? null
        );

        $revision->load('answers', 'media');
        $isFavorite = Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('question_id', $revision->question_id)->exists();

        return view('learning.question', [
            'course' => $course,
            'revision' => $revision,
            'reason' => null,
            'mode' => $validated['mode'],
            'topic' => $request->query('topic'),
            'moduleId' => $request->query('module'),
            'isFavorite' => $isFavorite,
            'startedAt' => now()->valueOf(),
            'answered' => true,
            'correct' => $attempt->correct,
            'selectedAnswerId' => $selectedAnswer?->id,
        ]);
    }
}
