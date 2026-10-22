<?php

namespace App\Http\Controllers;

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\ExamSession;
use App\Models\Favorite;
use App\Models\PraxisProgress;
use App\Models\Progress;
use App\Services\CourseProgressService;
use App\Services\EntitlementService;
use App\Services\LearningService;
use App\Services\SmarttrainerService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LearningController extends Controller
{
    public function overview(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseProgressService $courseProgress, CourseDefinition $course): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403, 'Für diesen Kurs liegt kein aktives Entitlement vor.');

        $course->loadMissing('praxisTasks', 'modules.questions');

        $moduleGroups = $courseProgress->moduleKategorieBreakdown($course, $tenant, $user);
        $overallPercent = $courseProgress->overallPercent($course, $tenant, $user);

        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get();
        $mastered = fn ($questionIds) => $progress->whereIn('question_id', $questionIds)->where('learning_state', 'gefestigt')->count();

        // Favoriten sind kursgebunden: nur Fragen zählen, die tatsächlich zu
        // diesem Kurs gehören, damit jeder Kurs seine eigenen Favoriten hat.
        $courseQuestionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique();
        $favoriteIds = Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('context', Favorite::CONTEXT_SMART_LEARNING)
            ->whereIn('question_id', $courseQuestionIds)->pluck('question_id');

        $praxisProgress = PraxisProgress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get();
        $praxisMastered = fn ($taskIds) => $praxisProgress->whereIn('praxis_task_id', $taskIds)->where('completed', true)->count();

        $praxisCategories = $course->praxisTasks
            ->groupBy('kategorie')
            ->map(function ($tasks, $kategorie) use ($praxisMastered) {
                $taskIds = $tasks->pluck('id');
                $total = $taskIds->count();
                $categoryMastered = $praxisMastered($taskIds);

                return [
                    'kategorie' => $kategorie,
                    'total' => $total,
                    'mastered' => $categoryMastered,
                    'percent' => $total > 0 ? (int) round($categoryMastered / $total * 100) : 0,
                ];
            })
            ->sortByDesc('total')
            ->values();

        return view('learning.overview', [
            'course' => $course,
            'overallPercent' => $overallPercent,
            'moduleGroups' => $moduleGroups,
            'favoritesTotal' => $favoriteIds->count(),
            'favoritesMastered' => $favoriteIds->isNotEmpty() ? $mastered($favoriteIds) : 0,
            'praxisCategories' => $praxisCategories,
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

        // Feste Positivliste statt eines beliebigen Query-Werts
        // (Sicherheitsreview M4) -- ein unbekannter Wert fällt auf den
        // Standardmodus zurück, statt unvalidiert weitergereicht zu werden
        // (u. a. landet mode als attempt.mode, varchar(40), in storeAttempt()).
        $mode = $request->query('mode', 'smarttrainer');
        if (! in_array($mode, ['smarttrainer', 'new', 'wrong', 'topic', 'favorites'], true)) {
            $mode = 'smarttrainer';
        }
        $topic = $request->query('topic');
        $moduleId = $request->query('module');
        $questionIds = $this->parseQuestionIds($request->query('questions'));

        if ($mode === 'favorites') {
            $courseQuestionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique();
            $favoriteIds = Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->where('context', Favorite::CONTEXT_SMART_LEARNING)
                ->whereIn('question_id', $courseQuestionIds)->pluck('question_id');
            $questionId = $favoriteIds->isNotEmpty() ? $favoriteIds->random() : null;
            $next = $questionId ? ['question' => ContentQuestion::find($questionId), 'reason' => 'Favorit'] : null;
        } else {
            $next = $smarttrainer->nextQuestion($tenant, $user, $course, $mode, $topic, $moduleId, $questionIds);
        }

        if (! $next) {
            return view('learning.empty', ['course' => $course, 'mode' => $mode]);
        }

        $revision = $next['question']->publishedRevision();
        abort_if(! $revision, 404, 'Für diese Frage liegt keine veröffentlichte Fassung vor.');
        $revision->load('answers', 'media');

        $isFavorite = Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('context', Favorite::CONTEXT_SMART_LEARNING)
            ->where('question_id', $next['question']->id)->exists();

        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('question_id', $next['question']->id)->first();

        return view('learning.question', [
            'course' => $course,
            'revision' => $revision,
            'reason' => $next['reason'],
            'mode' => $mode,
            'topic' => $topic,
            'moduleId' => $moduleId,
            'questionsParam' => $request->query('questions'),
            'isFavorite' => $isFavorite,
            'startedAt' => now()->valueOf(),
            'answered' => false,
            'correct' => null,
            'selectedAnswerId' => null,
            'selectedAnswerIds' => null,
            'streak' => $progress?->current_streak ?? 0,
            'mastered' => $progress?->learning_state === 'gefestigt',
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
            'answer_ids' => ['nullable', 'array'],
            'answer_ids.*' => ['uuid'],
            'known' => ['nullable', 'boolean'],
            // max:40 passend zur Spaltenbreite attempt.mode (varchar(40)) --
            // sonst scheitert ein zu langer Wert erst beim Insert mit einer
            // harten Postgres-Exception statt einem sauberen 422
            // (Sicherheitsreview M4). "in" beschränkt zusätzlich auf die
            // tatsächlich unterstützten Modi (siehe SmarttrainerService).
            'mode' => ['required', 'string', 'max:40', 'in:smarttrainer,new,wrong,topic,favorites'],
            // Bug-Review B19: die Antwortzeit kam bisher vom Client fertig
            // berechnet (Date.now() - startedAt, beide clientseitig) --
            // eine nachgehende Geräteuhr konnte das negativ werden lassen
            // und die gesamte Antwort wegen min:0 ablehnen. started_at_ms
            // ist nur der unverändert vom Server gerenderte Zeitpunkt
            // (LearningController::show(), 'startedAt' => now()->valueOf());
            // die Dauer wird unten aus der Differenz zur eigenen Uhr bei
            // Eingang berechnet, unabhängig von der Client-Uhr.
            'started_at_ms' => ['nullable', 'integer'],
        ]);

        $responseTimeMs = isset($validated['started_at_ms'])
            ? max(0, (int) round(microtime(true) * 1000) - $validated['started_at_ms'])
            : null;

        $revision = ContentQuestionRevision::where('id', $validated['revision_id'])
            ->where('editorial_status', 'published')
            ->firstOrFail();

        // Sicherheitsregel laut api_spec.md: question_id/revision_id müssen zu
        // Content gehören, der für den Kurs des Nutzers tatsächlich freigegeben ist.
        $courseQuestionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id');
        abort_unless($courseQuestionIds->contains($revision->question_id), 403, 'Frage gehört nicht zum berechtigten Kurs.');

        // Eine Frage, die gerade Teil einer laufenden Prüfungssession dieses
        // Nutzers ist, darf währenddessen nicht im Lernmodus bearbeitet
        // werden (Sicherheitsreview M2) -- sonst ließe sich die richtige
        // Antwort über die sofortige Rückmeldung hier einsehen und danach in
        // der noch offenen Prüfung eintragen.
        $isPartOfRunningExam = ExamSession::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'running')
            ->whereHas('questions', fn ($q) => $q->where('question_id', $revision->question_id))
            ->exists();

        abort_if($isPartOfRunningExam, 409, 'Diese Frage ist Teil einer laufenden Prüfung und kann währenddessen nicht im Lernmodus bearbeitet werden.');

        $selectedAnswer = null;
        $selectedAnswers = null;
        $selfAssessedKnown = null;

        if ($revision->question_type === 'multi_choice') {
            $selectedAnswers = ContentAnswer::where('revision_id', $revision->id)
                ->whereIn('id', $validated['answer_ids'] ?? [])->get();
        } elseif ($revision->question_type === 'open_text') {
            $selfAssessedKnown = array_key_exists('known', $validated) ? (bool) $validated['known'] : null;
        } elseif ($validated['answer_id'] ?? null) {
            $selectedAnswer = ContentAnswer::where('id', $validated['answer_id'])
                ->where('revision_id', $revision->id)
                ->firstOrFail();
        }

        $attempt = $learningService->recordAttempt(
            $tenant, $user, $revision, $selectedAnswer, $validated['mode'], $course, $responseTimeMs, $selectedAnswers, $selfAssessedKnown
        );

        $revision->load('answers', 'media');
        $isFavorite = Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('context', Favorite::CONTEXT_SMART_LEARNING)
            ->where('question_id', $revision->question_id)->exists();

        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('question_id', $revision->question_id)->first();

        return view('learning.question', [
            'course' => $course,
            'revision' => $revision,
            'reason' => null,
            'mode' => $validated['mode'],
            'topic' => $request->query('topic'),
            'moduleId' => $request->query('module'),
            'questionsParam' => $request->query('questions'),
            'isFavorite' => $isFavorite,
            'startedAt' => now()->valueOf(),
            'answered' => true,
            'correct' => $attempt->correct,
            'selectedAnswerId' => $attempt->selected_answer_id,
            'selectedAnswerIds' => $attempt->selected_answer_ids,
            'streak' => $progress?->current_streak ?? 0,
            'mastered' => $progress?->learning_state === 'gefestigt',
        ]);
    }

    private function parseQuestionIds(?string $questions): ?array
    {
        if (! $questions) {
            return null;
        }

        return array_values(array_filter(explode(',', $questions)));
    }
}
