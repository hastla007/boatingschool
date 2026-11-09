<?php

namespace App\Http\Controllers;

use App\Models\Attempt;
use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\Favorite;
use App\Models\Module;
use App\Models\PraxisProgress;
use App\Models\Progress;
use App\Services\CourseProgressService;
use App\Services\EntitlementService;
use App\Services\LearningFlow;
use App\Services\SmarttrainerService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class LearningController extends Controller
{
    public function overview(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseProgressService $courseProgress, CourseDefinition $course): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403, 'Dein Zugang zu diesem Kurs ist abgelaufen oder nicht freigeschaltet.');

        $course->loadMissing('praxisTasks', 'modules.questions');

        $moduleGroups = $courseProgress->moduleKategorieBreakdown($course, $tenant, $user);
        $overallPercent = $courseProgress->overallPercent($course, $tenant, $user);

        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get();
        $mastered = fn ($questionIds) => $progress->whereIn('question_id', $questionIds)->where('learning_state', 'gefestigt')->count();

        // Favoriten sind kursgebunden: nur Fragen zählen, die tatsächlich zu
        // diesem Kurs gehören, damit jeder Kurs seine eigenen Favoriten hat.
        //
        // Bug-Review (BB2: Favoriten zählen deaktivierte Fragen mit): eine
        // im Superadmin deaktivierte Frage (content_question.active) taucht
        // im Smarttrainer nicht mehr auf (siehe F7) und kann deshalb nie
        // "gefestigt" werden -- zählte aber bisher weiter im
        // Favoriten-Nenner mit, wodurch "X/Y gemeistert" dauerhaft hinter
        // dem tatsächlich erreichbaren Fortschritt zurückblieb, analog zum
        // bereits gefixten Gesamtfortschritt-Nenner in CourseProgressService.
        // Dieselbe Fragenmenge wie Favoriten-Seite und Favoriten-Modus
        // (inklusive Fragen der Prüfungsbögen), nur aktuell gültige.
        $favoriteIds = Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('context', Favorite::CONTEXT_SMART_LEARNING)
            ->whereIn('question_id', $this->courseQuestionIds($course))
            ->whereIn('question_id', ContentQuestion::query()->currentlyValid()->select('id'))
            ->pluck('question_id');

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
        LearningFlow $learningFlow,
        CourseDefinition $course,
    ): View {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403, 'Dein Zugang zu diesem Kurs ist abgelaufen oder nicht freigeschaltet.');

        // Feste Positivliste statt eines beliebigen Query-Werts
        // (Sicherheitsreview M4) -- ein unbekannter Wert fällt auf den
        // Standardmodus zurück, statt unvalidiert weitergereicht zu werden
        // (u. a. landet mode als attempt.mode, varchar(40), in storeAttempt()).
        $mode = $request->query('mode', 'smarttrainer');
        if (! in_array($mode, ['smarttrainer', 'new', 'wrong', 'topic', 'favorites'], true)) {
            $mode = 'smarttrainer';
        }
        // Bug-Review (Array-Query-Parameter): ?topic[]=x bzw. ?module[]=x
        // lieferte ein Array, das nextQuestion(?string ...) mit einem
        // TypeError (HTTP 500) quittierte -- nicht-string-förmige Werte
        // gelten jetzt als nicht gesetzt (siehe stringQueryParam()).
        $topic = $this->stringQueryParam($request, 'topic');
        $moduleId = $this->stringQueryParam($request, 'module');
        $questionIds = $this->parseQuestionIds($this->questionsQueryParam($request));

        // Auswahl der nächsten Frage gemeinsam mit der Mobile-API (LearningFlow).
        $next = $learningFlow->nextQuestion($tenant, $user, $course, $mode, $topic, $moduleId, $questionIds);

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
            'questionsParam' => $this->questionsQueryParam($request),
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
        LearningFlow $learningFlow,
        CourseDefinition $course,
    ) {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403, 'Dein Zugang zu diesem Kurs ist abgelaufen oder nicht freigeschaltet.');

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
            'started_at_ms' => ['nullable', 'integer', 'min:0', 'max:'.intdiv(PHP_INT_MAX, 2)],
        ]);

        // Prüfung und Speichern gemeinsam mit der Mobile-API (LearningFlow).
        $attempt = $learningFlow->submitAttempt($tenant, $user, $course, $validated);

        return redirect()->route('learning.attempts.show', array_filter([
            'course' => $course,
            'attempt' => $attempt,
            'mode' => $validated['mode'],
            'topic' => $this->stringQueryParam($request, 'topic'),
            'module' => $this->stringQueryParam($request, 'module'),
            'questions' => $this->questionsQueryParam($request),
        ]));
    }

    public function showAttempt(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, Attempt $attempt): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403, 'Dein Zugang zu diesem Kurs ist abgelaufen oder nicht freigeschaltet.');
        abort_unless($attempt->user_id === $user->id, 403);
        abort_unless($attempt->course_id === $course->id, 404);

        $mode = $request->query('mode', 'smarttrainer');
        if (! in_array($mode, ['smarttrainer', 'new', 'wrong', 'topic', 'favorites'], true)) {
            $mode = 'smarttrainer';
        }

        $revision = $attempt->revision()->with('answers', 'media')->firstOrFail();

        $isFavorite = Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('context', Favorite::CONTEXT_SMART_LEARNING)
            ->where('question_id', $revision->question_id)->exists();

        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('question_id', $revision->question_id)->first();

        return view('learning.question', [
            'course' => $course,
            'revision' => $revision,
            'reason' => null,
            'mode' => $mode,
            'topic' => $this->stringQueryParam($request, 'topic'),
            'moduleId' => $this->stringQueryParam($request, 'module'),
            'questionsParam' => $this->questionsQueryParam($request),
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

    /**
     * Bug-Review (SRC-UBI: Fragen der Prüfungsbögen gehören nicht zum
     * Kurs): der Kombikurs SRC-UBI lernt bewusst nur die Module SRC und
     * UBI_ERG (siehe UbiErgaenzungSeeder), spiegelt aber auch die zwölf
     * UBI-Vollkurs-Bögen 101-112, deren Fragen aus dem Modul UBI stammen.
     * storeAttempt() lehnte eine Antwort auf genau diese Fragen mit 403 ab
     * (z. B. aus "Falsche Fragen erneut lernen" oder einem Prüfungs-
     * Favoriten). Zum Kurs gehören deshalb die Fragen seiner Module UND
     * seiner Prüfungsbögen -- analog zu FavoriteController.
     *
     * @return Collection<int, string>
     */
    private function courseQuestionIds(CourseDefinition $course): Collection
    {
        return $course->contentQuestionIds();
    }

    private function parseQuestionIds(?string $questions): ?array
    {
        if (! $questions) {
            return null;
        }

        return array_values(array_filter(explode(',', $questions)));
    }

    /**
     * Bug-Review (Query-Parameter-Validierung): ?questions=a,b wird als
     * String erwartet (siehe parseQuestionIds()) -- ein manipulierter
     * Request mit ?questions[]=a&questions[]=b liefert von Laravel aus
     * stattdessen ein Array, das array_filter(explode(',', ...)) mit
     * einem TypeError scheitern ließ, unbehandelt mit HTTP 500. Analog zu
     * VideoCourseController::parseModuleIds() wird ein nicht-string-
     * förmiger Wert hier einfach ignoriert statt weitergereicht.
     */
    private function questionsQueryParam(Request $request): ?string
    {
        return $this->stringQueryParam($request, 'questions');
    }

    private function stringQueryParam(Request $request, string $key): ?string
    {
        $raw = $request->query($key);

        return is_string($raw) ? $raw : null;
    }
}
