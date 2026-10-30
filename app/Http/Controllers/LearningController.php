<?php

namespace App\Http\Controllers;

use App\Models\Attempt;
use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\ExamSession;
use App\Models\Favorite;
use App\Models\Module;
use App\Models\PraxisProgress;
use App\Models\Progress;
use App\Services\CourseProgressService;
use App\Services\EntitlementService;
use App\Services\ExamEvaluationService;
use App\Services\LearningService;
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

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403, 'Für diesen Kurs liegt kein aktives Entitlement vor.');

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
        $courseQuestionIds = ContentQuestion::currentlyValidIds($course->modules->flatMap(fn ($m) => $m->questions));
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
        // Bug-Review (Array-Query-Parameter): ?topic[]=x bzw. ?module[]=x
        // lieferte ein Array, das nextQuestion(?string ...) mit einem
        // TypeError (HTTP 500) quittierte -- nicht-string-förmige Werte
        // gelten jetzt als nicht gesetzt (siehe stringQueryParam()).
        $topic = $this->stringQueryParam($request, 'topic');
        $moduleId = $this->stringQueryParam($request, 'module');
        $questionIds = $this->parseQuestionIds($this->questionsQueryParam($request));

        if ($mode === 'favorites') {
            // Gültigkeit prüft die Auswahl unten (currentlyValid()) ohnehin.
            $courseQuestionIds = $this->courseQuestionIds($course);
            $favoriteIds = Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->where('context', Favorite::CONTEXT_SMART_LEARNING)
                ->whereIn('question_id', $courseQuestionIds)->pluck('question_id');

            // Bug-Review (Deaktivierte Favoriten führen zu Endlosschleife):
            // ein als Favorit markierte Frage bleibt es auch, wenn ein
            // Superadmin sie später deaktiviert (content_question.active)
            // oder ihre einzige Revision (noch) nicht veröffentlicht ist --
            // diese Auswahl hier zog bisher trotzdem jede Favoriten-ID ohne
            // Prüfung in Betracht. Landete $favoriteIds->random() dabei
            // (z. B. weil es der einzige verbliebene Favorit ist) immer
            // wieder auf genau dieser kaputten Frage, endete jeder Aufruf
            // dieser Seite im abort_if() weiter unten (keine veröffentlichte
            // Fassung) -- ohne je einen funktionierenden Favoriten oder die
            // "keine Favoriten"-Ansicht zu erreichen. SmarttrainerService::
            // nextQuestion() filtert bereits analog auf active=true (siehe
            // Bug-Review B12); hier zusätzlich auf eine existierende
            // veröffentlichte Revision, da genau das hier (anders als bei
            // Smarttrainer) sonst erst nach der Auswahl geprüft wird.
            //
            // Bug-Review X10 (Favoriten zeigen Fragen aus laufender
            // Prüfung): storeAttempt() lehnt eine Antwort auf eine Frage
            // ab, die Teil einer noch laufenden Prüfungssession dieses
            // Nutzers ist (siehe unten) -- der Favoriten-Modus bot genau
            // so eine Frage bisher trotzdem aktiv an und scheiterte erst
            // beim anschließenden Abschicken mit 409, analog zur bereits
            // für den Smarttrainer behobenen Bug-Review "Smarttrainer
            // blockiert während laufender Prüfung".
            $blockedQuestionIds = $smarttrainer->runningExamQuestionIds($tenant, $user);
            $next = ContentQuestion::whereIn('id', $favoriteIds)
                ->currentlyValid()
                ->when($blockedQuestionIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $blockedQuestionIds))
                ->inRandomOrder()
                ->first();
            $next = $next ? ['question' => $next, 'reason' => 'Favorit'] : null;
        } else {
            $learningCourse = $questionIds !== null ? $this->withExamPaperQuestions($course, $questionIds) : $course;
            $next = $smarttrainer->nextQuestion($tenant, $user, $learningCourse, $mode, $topic, $moduleId, $questionIds);
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
        LearningService $learningService,
        ExamEvaluationService $examEvaluation,
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

        // Bug-Review B12 (Folgefehler): storeAttempt() prüfte bisher nur
        // editorial_status, nicht ob die zugehörige Frage im Superadmin
        // deaktiviert wurde (content_question.active) -- anders als die
        // Auswahl im Smarttrainer/Prüfung/Video-Quiz, die solche Fragen
        // konsequent ausblendet, nahm storeAttempt eine direkt per
        // revision_id eingereichte deaktivierte Frage trotzdem an.
        //
        // Bug-Review BB16 ("Gültig ab"/"Gültig bis" wirkungslos): dieselbe
        // Lücke bestand für valid_from/valid_to -- eine außerhalb ihres
        // Gültigkeitsfensters liegende Frage ließ sich hier weiterhin
        // beantworten, obwohl sie an keiner Auswahl-Stelle mehr angeboten
        // wird.
        //
        // Bug-Review (Antwort geht bei neuer Revision verloren): wurde
        // zwischen Anzeige und Abschicken eine neue Fassung derselben Frage
        // veröffentlicht, ist die angezeigte Revision inzwischen
        // "deprecated" -- der POST mit ihrer revision_id endete bisher mit
        // 404, die Antwort war verloren. Eine abgelöste Fassung einer
        // weiterhin gültigen Frage wird deshalb ebenfalls angenommen und
        // gegen genau diese (angezeigte) Fassung bewertet.
        $revision = ContentQuestionRevision::where('id', $validated['revision_id'])
            ->whereIn('editorial_status', ['published', 'deprecated'])
            ->whereHas('question', fn ($q) => $q->currentlyValid())
            ->firstOrFail();

        // Sicherheitsregel laut api_spec.md: question_id/revision_id müssen zu
        // Content gehören, der für den Kurs des Nutzers tatsächlich freigegeben ist.
        abort_unless($this->courseQuestionIds($course)->contains($revision->question_id), 403, 'Frage gehört nicht zum berechtigten Kurs.');

        // Eine Frage, die gerade Teil einer laufenden Prüfungssession dieses
        // Nutzers ist, darf währenddessen nicht im Lernmodus bearbeitet
        // werden (Sicherheitsreview M2) -- sonst ließe sich die richtige
        // Antwort über die sofortige Rückmeldung hier einsehen und danach in
        // der noch offenen Prüfung eintragen.
        //
        // Bug-Review (Hoch): die Prüfung fragte bisher nur status='running'
        // ab, ohne die Zeit zu berücksichtigen -- eine Prüfungssession bleibt
        // in der DB aber so lange "running", bis sie ausgewertet wird
        // (ExamController::show()/answer()/finish() tun das NUR, wenn der
        // Nutzer die Prüfungsseite selbst erneut aufruft). Ließ ein Nutzer
        // eine zeitlich abgelaufene Prüfung einfach liegen, ohne sie noch
        // einmal zu öffnen, blieb jede ihrer Fragen hier PERMANENT gesperrt
        // (409), auch lange nach Ablauf der Prüfungszeit -- der Smarttrainer
        // zeigte dieselbe gesperrte Frage dann immer wieder erneut an. Eine
        // abgelaufene Session wird deshalb jetzt genau wie in ExamController
        // sofort ausgewertet (statt die Antwort hier anzunehmen UND
        // gleichzeitig zu blockieren), statt weiterhin als blockierend zu
        // gelten.
        $runningExamSessions = ExamSession::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'running')
            ->whereHas('questions', fn ($q) => $q->where('question_id', $revision->question_id))
            ->get();

        foreach ($runningExamSessions as $runningExamSession) {
            if ($examEvaluation->timeExpired($runningExamSession)) {
                $examEvaluation->evaluate($runningExamSession);

                continue;
            }

            abort(409, 'Diese Frage ist Teil einer laufenden Prüfung und kann währenddessen nicht im Lernmodus bearbeitet werden.');
        }

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
            $tenant, $user, $revision, $selectedAnswer, $validated['mode'], $course, $responseTimeMs, $selectedAnswers, $selfAssessedKnown, $validated['started_at_ms'] ?? null
        );

        // Bug-Review B20: dieser Handler rendert die beantwortete Frage
        // nicht mehr direkt als POST-Antwort -- ein Neuladen (F5) hätte den
        // Browser sonst zur erneuten Formular-Einreichung (derselbe
        // POST) veranlasst und einen zweiten Attempt für dieselbe Frage
        // angelegt. Der Redirect auf die GET-Route showAttempt() ist bei
        // einem Reload hingegen ein harmloses erneutes Laden desselben,
        // bereits gespeicherten Attempts.
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

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403, 'Für diesen Kurs liegt kein aktives Entitlement vor.');
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

    /**
     * "Falsche Fragen erneut lernen" (?questions=...) übergibt die falsch
     * beantworteten Fragen einer Prüfung -- SmarttrainerService::
     * nextQuestion() wählt aber nur unter den Modulfragen des Kurses aus
     * und übersprang Bogenfragen außerhalb der Module (siehe
     * courseQuestionIds()) stillschweigend. Nur für diese explizit
     * angefragten Fragen bekommt eine Kopie des Kurses ein zusätzliches,
     * nicht gespeichertes Modul mit genau diesen Bogenfragen; der normale
     * Lernmodus bleibt beim Modulinhalt des Kurses.
     *
     * @param  list<string>  $questionIds
     */
    private function withExamPaperQuestions(CourseDefinition $course, array $questionIds): CourseDefinition
    {
        $moduleQuestionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id');
        $extraIds = $course->examPaperQuestionIds()->intersect($questionIds)->diff($moduleQuestionIds);

        if ($extraIds->isEmpty()) {
            return $course;
        }

        $paperModule = new Module(['code' => 'EXAM_PAPERS', 'name' => 'Prüfungsbögen']);
        $paperModule->setRelation('questions', ContentQuestion::whereIn('id', $extraIds)
            ->with(['revisions' => fn ($q) => $q->where('editorial_status', 'published')->orderByDesc('revision_no')])
            ->get());

        $scopedCourse = clone $course;
        $scopedCourse->setRelation('modules', $course->modules->concat([$paperModule]));

        return $scopedCourse;
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
