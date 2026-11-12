<?php

namespace App\Http\Controllers;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\ExamSessionQuestion;
use App\Models\Favorite;
use App\Models\Progress;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\ExamActions;
use App\Services\ExamEvaluationService;
use App\Services\ExamSessionStarter;
use App\Support\ComboExamGroups;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403, 'Dein Zugang zu diesem Kurs ist abgelaufen oder nicht freigeschaltet.');

        $papers = $course->examPapers;

        if ($papers->isNotEmpty()) {
            return $this->papersOverview($tenant, $user, $course, $papers);
        }

        // Review-Runde 12: liegt nur ein noch nicht freigegebener Entwurf vor,
        // sagt die Seite das (statt „kein Regelwerk“); starten lässt er sich nicht.
        $ruleSet = $course->activeExamRuleSet() ?? $course->examRuleSets()->orderByDesc('version')->first();

        return view('exam.intro', [
            'course' => $course,
            'ruleSet' => $ruleSet,
            'hasNavigationTasks' => $course->navigationTasks()->exists(),
        ]);
    }

    /** @param Collection<int, ExamPaper> $papers */
    private function papersOverview(Tenant $tenant, User $user, CourseDefinition $course, Collection $papers): View
    {
        // Bug-Review B5 (Folgefehler): startPaper() lehnt einen Versuch für
        // ein nicht freigegebenes Regelwerk bereits mit 409 ab -- diese
        // Übersicht zeigte die "Bogen N"-Start-Buttons aber unverändert an,
        // ein Klick führte also ins Leere statt den Kurs klar als noch
        // nicht freigegeben zu kennzeichnen (analog zu exam/intro.blade.php).
        // In Kombikursen gilt das je Bogengruppe, mit dem Regelwerk des
        // jeweiligen Teilkurses (siehe CourseDefinition::examRuleSetForPaper()).
        $ruleSetsBySource = [];
        $ruleSetFor = function ($paper) use ($course, &$ruleSetsBySource) {
            $key = ComboExamGroups::sourceCourseFor($course->code, $paper->paper_number) ?? '';

            return $ruleSetsBySource[$key] ??= ['ruleSet' => $course->examRuleSetForPaper($paper)];
        };

        $paperIds = $papers->pluck('id');

        $latestEvaluatedSessions = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->whereIn('paper_id', $paperIds)
            ->where('status', 'evaluated')
            ->orderByDesc('submitted_at')
            ->get()
            ->unique('paper_id')
            ->keyBy('paper_id');
        // Angefangene Bögen (noch in der Zeit): Kachel zeigt „läuft – fortsetzen“.
        $runningPaperIds = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->whereIn('paper_id', $paperIds)->where('status', 'running')->get()
            ->filter(fn ($session) => $session->stillOpen(app(ExamEvaluationService::class)->timeExpired($session)))
            ->pluck('paper_id')->flip();

        // Bug-Review (Langsame Prüfungsübersicht): für jeden Bogen eine
        // eigene COUNT-Query auf paperQuestions() und für jeden bereits
        // ausgewerteten Versuch eine eigene COUNT-Query auf
        // questions()->where('correct', true) ergaben bei N Bögen bis zu
        // 2N zusätzliche Round-Trips -- bei einem Kombikurs mit vielen
        // Bögen (z. B. SBF-SEE-BIN) war die Seite dadurch spürbar langsam.
        // Beide Zählungen laufen jetzt als je eine gruppierte
        // Aggregat-Query vorab.
        // Die Aggregat-Spalte darf nicht "total"/"correct" o. ä. heißen, wie
        // eine echte Modell-Spalte -- ExamSessionQuestion::casts() wandelt
        // "correct" sonst per boolean-Cast in true/false um (count(*)=2
        // wurde dadurch zu true), weil Eloquent den Cast anhand des
        // Attributnamens anwendet, nicht anhand der tatsächlichen Query.
        // Bug-Review (BB1: Prüfungskacheln zeigen "x/24" trotz deaktivierter
        // Frage): startPaper() spielt eine deaktivierte Frage (content_
        // question.active) gar nicht erst aus (siehe B12-Kommentar dort),
        // die Übersicht zählte für die Sollzahl je Bogen hier aber
        // unverändert alle exam_paper_question-Zeilen mit, unabhängig vom
        // Aktiv-Status der referenzierten Frage -- die Kachel zeigte z. B.
        // "22/24", obwohl die tatsächlich gestellte Prüfung nur 22 Fragen
        // umfasste. Nur aktive Fragen zählen jetzt zur Sollzahl.
        $totalsByPaper = ExamPaperQuestion::whereIn('exam_paper_id', $paperIds)
            ->join('content_question', 'content_question.id', '=', 'exam_paper_question.question_id')
            ->whereIn('content_question.id', ContentQuestion::query()->currentlyValid()->select('id'))
            ->selectRaw('exam_paper_id, count(*) as total_count')
            ->groupBy('exam_paper_id')
            ->pluck('total_count', 'exam_paper_id');

        $correctBySession = ExamSessionQuestion::whereIn('exam_session_id', $latestEvaluatedSessions->pluck('id'))
            ->where('correct', true)
            ->selectRaw('exam_session_id, count(*) as correct_count')
            ->groupBy('exam_session_id')
            ->pluck('correct_count', 'exam_session_id');
        // Fragenzahl des tatsächlich abgelegten Bogens: wird eine Frage später
        // deaktiviert, zeigte die Kachel sonst z. B. „24/23“.
        $questionCountBySession = ExamSessionQuestion::whereIn('exam_session_id', $latestEvaluatedSessions->pluck('id'))
            ->selectRaw('exam_session_id, count(*) as question_count')
            ->groupBy('exam_session_id')
            ->pluck('question_count', 'exam_session_id');

        $paperStats = $papers->map(function ($paper) use ($latestEvaluatedSessions, $course, $ruleSetFor, $totalsByPaper, $correctBySession, $runningPaperIds, $questionCountBySession) {
            $session = $latestEvaluatedSessions->get($paper->id);
            $comboInfo = ComboExamGroups::infoFor($course->code, $paper->paper_number);
            $ruleSet = $ruleSetFor($paper)['ruleSet'];

            return [
                'paper' => $paper,
                'attempted' => (bool) $session,
                'running' => $runningPaperIds->has($paper->id),
                'percent' => $session ? (int) round($session->score * 100) : 0,
                // Review-Runde 11: der Prozentwert zählt nur den Fragebogen --
                // ohne Ergebnis wirkte ein an der Navigation gescheiterter Bogen
                // mit 100 % wie bestanden.
                'passed' => $session?->passed,
                'correct' => $session ? $correctBySession->get($session->id, 0) : 0,
                'total' => $session ? (int) $questionCountBySession->get($session->id, 0) : $totalsByPaper->get($paper->id, 0),
                'group' => $comboInfo['label'] ?? null,
                'displayNumber' => $comboInfo['displayNumber'] ?? $paper->paper_number,
                'startable' => (bool) $ruleSet,
                'ruleSet' => $ruleSet,
            ];
        });
        $isVerified = $paperStats->contains('startable', true);

        $attemptedStats = $paperStats->filter(fn ($s) => $s['attempted']);
        $overallPercent = $attemptedStats->isNotEmpty() ? (int) round($attemptedStats->avg('percent')) : 0;

        // Favoriten sind kursgebunden: nur Fragen zählen, die tatsächlich zu
        // diesem Kurs gehören, damit jeder Kurs seine eigenen Favoriten hat.
        //
        // Bug-Review (Favoritenzähler zählt deaktivierte Fragen): analog zu
        // LearningController (BB2) zählen nur aktuell gültige Fragen (active,
        // veröffentlichte Revision) -- eine deaktivierte Frage kann nie
        // "gefestigt" werden und hielt den Zähler sonst dauerhaft zurück.
        // Bug-Review (SRC-UBI): zum Kurs gehören auch die Fragen seiner
        // Prüfungsbögen (siehe FavoriteController::courseQuestionIds()),
        // sonst fehlten z. B. die UBI-Bogenfragen im Kombikurs SRC-UBI.
        $courseQuestionIds = ContentQuestion::whereIn('id', $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')
            ->merge(ExamPaperQuestion::whereIn('exam_paper_id', $paperIds)->pluck('question_id'))
            ->unique()
            ->values())
            ->currentlyValid()
            ->pluck('id');
        $favoriteIds = Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('context', Favorite::CONTEXT_EXAM)
            ->whereIn('question_id', $courseQuestionIds)->pluck('question_id');
        $favoritesMastered = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->whereIn('question_id', $favoriteIds)->where('learning_state', 'gefestigt')->count();

        return view('exam.papers', [
            'course' => $course,
            'isVerified' => $isVerified,
            'paperStats' => $paperStats,
            'overallPercent' => $overallPercent,
            'favoritesTotal' => $favoriteIds->count(),
            'favoritesMastered' => $favoritesMastered,
            'hasNavigationTasks' => $course->navigationTasks()->exists(),
        ]);
    }

    public function startPaper(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, ExamEvaluationService $examEvaluation, CourseDefinition $course, ExamPaper $paper): Response
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();
        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403, 'Dein Zugang zu diesem Kurs ist abgelaufen oder nicht freigeschaltet.');
        abort_unless($paper->course_id === $course->id, 404);

        // Kombikurse: der Bogen läuft nach dem Regelwerk seines Teilkurses
        // (Bestehensgrenze und Zeitlimit), die Session verweist auf genau
        // dieses Regelwerk -- ExamEvaluationService und Zeitlimit-Prüfung
        // lesen es von dort.
        $ruleSet = $course->examRuleSetForPaper($paper);
        abort_unless($ruleSet && $ruleSet->isVerified(), 409, 'Für diesen Kurs liegt noch kein fachlich freigegebenes Prüfungsregelwerk vor.');

        // Bug-Review B4: der alte Ablauf prüfte erst (lesend), ob bereits
        // eine laufende Session für diesen Bogen existiert, und legte dann
        // (schreibend) eine neue an -- zwischen beiden Schritten lag kein
        // Lock, ein Doppelklick auf "Jetzt starten" konnte also zwei
        // "running"-Sessions für denselben Bogen erzeugen. Der
        // Advisory-Lock serialisiert gleichzeitige Start-Versuche derselben
        // Tenant/Nutzer/Bogen-Kombination für die Dauer der Transaktion; der
        // Unique-Index aus der Migration 000460 sichert das zusätzlich auf
        // Datenbankebene ab.
        // Gemeinsam mit der Mobile-API (ExamSessionStarter).
        return redirect()->route('exam.show', app(ExamSessionStarter::class)->startPaper($tenant, $user, $course, $ruleSet, $paper));
    }

    public function start(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, ExamEvaluationService $examEvaluation, CourseDefinition $course): Response
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();
        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403, 'Dein Zugang zu diesem Kurs ist abgelaufen oder nicht freigeschaltet.');

        // Bug-Review (Zufallsprüfung bei SRC/FKN hat nur 10 statt 24/15
        // Fragen): intro() zeigt für einen Kurs mit echten Prüfungsbögen
        // (examPapers) ausschließlich papersOverview() -- exam/intro.blade.php
        // mit dem Formular auf diese Route wird für einen solchen Kurs nie
        // gerendert, die Route blieb aber live erreichbar. Bei SRC/FKN griff
        // dieser Weg auf ein offenbar aus der Zeit vor den echten,
        // offiziellen Prüfungsbögen stammendes ExamRuleSet-Blueprint zurück,
        // das nur 10 statt der echten 24 (SRC) bzw. 15 (FKN) Fragen pro Bogen
        // zusammenstellte -- nur über einen direkten POST-Aufruf erreichbar,
        // ohne jeden UI-Link. Für einen Kurs mit echten Prüfungsbögen ist die
        // zufällig zusammengestellte Prüfung kein gültiger, zweiter Weg,
        // sondern veraltet; sie bleibt deshalb jetzt auf Kurse ohne eigene
        // Prüfungsbögen beschränkt (analog zur Verzweigung in intro()).
        abort_if($course->examPapers()->exists(), 409, 'Für diesen Kurs gibt es feste Prüfungsbögen – die zufällig zusammengestellte Prüfung ist hier nicht verfügbar.');

        $ruleSet = $course->activeExamRuleSet();
        abort_unless($ruleSet && $ruleSet->isVerified(), 409, 'Für diesen Kurs liegt noch kein fachlich freigegebenes Prüfungsregelwerk vor.');

        // Bug-Review N20: derselbe Doppelklick-Fehler wie B4 (startPaper()),
        // nur für die zufällig zusammengestellte Prüfung ohne Bogen -- dafür
        // gab es bisher weder einen Advisory-Lock noch einen Unique-Index.
        // Ein Doppelklick auf "Zufallsprüfung starten" konnte zwei parallele
        // "running"-Sessions für denselben Kurs anlegen. Der Unique-Index
        // aus Migration 000466 sichert das zusätzlich auf Datenbankebene ab.
        // Gemeinsam mit der Mobile-API (ExamSessionStarter).
        return redirect()->route('exam.show', app(ExamSessionStarter::class)->startRandom($tenant, $user, $course, $ruleSet));
    }

    /** Navigationsaufgabe der Prüfung: alle Teilantworten auf einmal abgeben. */
    public function submitNavigation(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, ExamEvaluationService $examEvaluation, ExamSession $examSession): Response
    {
        $this->authorizeSession($request, $tenantContext, $entitlements, $examSession);
        $validated = $request->validate(['answers' => ['array'], 'answers.*' => ['nullable', 'string', 'max:500']]);

        // Gemeinsam mit der Mobile-API (ExamActions).
        return match (app(ExamActions::class)->submitNavigation($examSession, $validated['answers'] ?? [])) {
            ExamActions::EVALUATED => redirect()->route('exam.result', $examSession),
            ExamActions::ALREADY_ANSWERED => redirect()->route('exam.show', $examSession)->with('status', 'Die Navigationsaufgabe ist bereits abgegeben.'),
            ExamActions::QUESTIONS_OPEN => redirect()->route('exam.show', $examSession)->with('status', 'Bitte zuerst alle Fragen beantworten – danach folgt die Navigationsaufgabe.'),
            ExamActions::EXPIRED => redirect()->route('exam.result', $examSession)->with('status', 'Die Prüfungszeit ist abgelaufen – hier ist dein Ergebnis.'),
            default => redirect()->route('exam.show', $examSession),
        };
    }

    /** Selbsteinschätzung der Teilfragen, die sich nicht automatisch prüfen lassen; danach Auswertung. */
    public function assessNavigation(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, ExamEvaluationService $examEvaluation, ExamSession $examSession): Response
    {
        $this->authorizeSession($request, $tenantContext, $entitlements, $examSession);
        $validated = $request->validate(['known' => ['array'], 'known.*' => ['in:0,1']]);
        $outcome = app(ExamActions::class)->assessNavigationAndFinish($examSession, $validated['known'] ?? []);

        return $outcome === ExamActions::EXPIRED
            ? redirect()->route('exam.result', $examSession)->with('status', 'Die Zeit für die Selbsteinschätzung ist abgelaufen – hier ist dein Ergebnis.')
            : redirect()->route('exam.result', $examSession);
    }

    public function show(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, ExamEvaluationService $examEvaluation, ExamSession $examSession): View|Response
    {
        $this->authorizeSession($request, $tenantContext, $entitlements, $examSession);

        if ($examSession->status === 'evaluated') {
            return redirect()->route('exam.result', $examSession);
        }

        // Die Selbsteinschätzung der Navigation zählt nicht zur Prüfungszeit.
        // Nachfrist für die Abgabe der Navigationsaufgabe wie überall sonst
        // (stillOpen): ein Neuladen innerhalb der Frist wertet nicht vorzeitig aus.
        if ($examSession->status === 'running' && ! $examSession->stillOpen($examEvaluation->timeExpired($examSession))) {
            return $this->finish($request, $tenantContext, $entitlements, $examEvaluation, $examSession);
        }

        $current = $examSession->questions()->whereNull('answered_at')->orderBy('position')->with('revision.answers', 'revision.media')->first();

        if (! $current && $examSession->hasNavigationPart()) {
            $remaining = $examSession->effectiveRuleSet()->time_limit_seconds
                ? max(0, (int) ($examSession->effectiveRuleSet()->time_limit_seconds - $examSession->started_at->diffInSeconds(now())))
                : null;
            $answers = $examSession->navigationAnswers()->with('question')->get();
            $toAssess = $answers;
            if (! $examSession->navigation_submitted_at || $examSession->inNavigationAssessment()) {
                return view('exam.navigation-part', [
                    'examSession' => $examSession,
                    'task' => $examSession->navigationTaskAsStarted(),
                    'answers' => $answers,
                    'toAssess' => $toAssess,
                    'step' => $examSession->navigation_submitted_at ? 'assess' : 'answer',
                    'rule' => $examSession->effectiveRuleSet()->navigationRule(),
                    'remainingSeconds' => $remaining,
                ]);
            }
        }

        if (! $current) {
            return $this->finish($request, $tenantContext, $entitlements, $examEvaluation, $examSession);
        }

        $total = $examSession->questions()->count();
        $answered = $examSession->questions()->whereNotNull('answered_at')->count();
        // Bug-Review B23/B24: diffInSeconds() liefert einen float mit
        // Mikrosekunden-Bruchteil -- das löste beim Rendern per gmdate()
        // eine Deprecation-Warnung aus ("Implicit conversion from float to
        // int loses precision"). (int) rundet hier zuverlässig ab.
        $remainingSeconds = $examSession->effectiveRuleSet()->time_limit_seconds
            ? max(0, (int) ($examSession->effectiveRuleSet()->time_limit_seconds - $examSession->started_at->diffInSeconds(now())))
            : null;

        $isFavorite = Favorite::where('tenant_id', $examSession->tenant_id)->where('user_id', $examSession->user_id)
            ->where('context', Favorite::CONTEXT_EXAM)
            ->where('question_id', $current->question_id)->exists();

        return view('exam.question', [
            'examSession' => $examSession,
            'current' => $current,
            'total' => $total,
            'answered' => $answered,
            'remainingSeconds' => $remainingSeconds,
            'isFavorite' => $isFavorite,
        ]);
    }

    public function answer(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, ExamEvaluationService $examEvaluation, ExamSession $examSession): Response
    {
        $this->authorizeSession($request, $tenantContext, $entitlements, $examSession);

        // Bug-Review (AA4): nach Ablauf der Zeit (oder bei einem Doppelklick
        // danach) landet der Lernende direkt beim fertigen Ergebnis statt auf
        // einer Fehlerseite.
        if ($examSession->status === 'evaluated') {
            return redirect()->route('exam.result', $examSession);
        }

        $validated = $request->validate([
            'position' => ['required', 'integer', 'min:1', 'max:1000'],
            'answer_id' => ['nullable', 'uuid'],
            'answer_ids' => ['nullable', 'array'],
            'answer_ids.*' => ['uuid'],
            'known' => ['nullable', 'boolean'],
        ]);

        // Gemeinsam mit der Mobile-API (ExamActions): Antwort festschreiben,
        // abgelaufene Zeit, Doppelklick (die erste Antwort zählt).
        return match (app(ExamActions::class)->answer($examSession, (int) $validated['position'], $validated)) {
            ExamActions::EVALUATED => redirect()->route('exam.result', $examSession),
            ExamActions::EXPIRED => redirect()->route('exam.result', $examSession)
                ->with('status', 'Die Prüfungszeit ist abgelaufen – hier ist dein Ergebnis.'),
            default => redirect()->route('exam.show', $examSession),
        };
    }

    /**
     * Bug-Review Oberfläche U9: die Musterantwort offener Fragen stand
     * bisher immer schon im initialen HTML von show() -- Alpines
     * x-if="revealed" blendet den Block nur im DOM aus, verhindert aber
     * nicht, dass Blade den Text serverseitig in die HTTP-Antwort
     * schreibt. Sie wird jetzt erst hier, nach einem expliziten Klick auf
     * "Musterantwort anzeigen", ausgeliefert; revealed_at macht diesen
     * Abruf zugleich zur serverseitigen Voraussetzung für die
     * Selbsteinschätzung in answer().
     */
    public function reveal(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, ExamSession $examSession): Response
    {
        $this->authorizeSession($request, $tenantContext, $entitlements, $examSession);
        $validated = $request->validate([
            'position' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $revealed = app(ExamActions::class)->reveal($examSession, (int) $validated['position']);
        // Doppelklick: die Frage ist schon beantwortet, weiter mit der nächsten.
        if ($revealed['outcome'] === ExamActions::ALREADY_ANSWERED) {
            return redirect()->route('exam.show', $examSession);
        }

        return response()->json(['answer_text' => $revealed['answer_text']]);
    }

    public function finish(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, ExamEvaluationService $examEvaluation, ExamSession $examSession): Response
    {
        $this->authorizeSession($request, $tenantContext, $entitlements, $examSession);
        app(ExamActions::class)->finish($examSession);

        return redirect()->route('exam.result', $examSession);
    }

    public function result(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, ExamSession $examSession): View
    {
        $this->authorizeSession($request, $tenantContext, $entitlements, $examSession);
        abort_unless($examSession->status === 'evaluated', 409, 'Prüfung ist noch nicht abgeschlossen.');

        $questions = $examSession->questions()->with('revision.answers')->orderBy('position')->get();
        // Bug-Review B2: Collection::where() vergleicht standardmäßig lose
        // (null == false wäre wahr) -- whereStrict erzwingt ===.
        $incorrect = $questions->whereStrict('correct', false);
        // Bug-Review B2 (Folgefehler): die Auswertung setzt correct=false
        // auch für nie beantwortete Fragen (siehe ExamEvaluationService) --
        // ohne diese Trennung erschienen unbeantwortete Fragen fälschlich
        // als "falsch beantwortet" statt als eigene Kategorie.
        $wrong = $incorrect->whereNotNull('answered_at');
        $unanswered = $incorrect->whereNull('answered_at');

        // Review-Runde 11: dieselben Themennamen wie im Lernstand.
        $topicBreakdown = $questions->groupBy(fn ($q) => ($q->revision?->smartmodus_kategorie ?: $q->revision?->topic) ?? '—')
            ->map(fn ($rows) => [
                'total' => $rows->count(),
                'correct' => $rows->where('correct', true)->count(),
            ]);

        // Bug-Review (FKN offene Fragen): offene Fragen (open_text) fließen
        // seit Bug-Review "Hoch" oben per Selbsteinschätzung in die Quote
        // ein -- ohne automatische Freitext-Prüfung bleibt das manipulierbar
        // (siehe ExamEvaluationService). Das Ergebnis weist deshalb jetzt
        // aus, wie viele der erreichten Punkte auf Selbsteinschätzung
        // beruhen, statt das stillschweigend in der Gesamtquote zu verstecken.
        $openTextQuestions = $questions->where('revision.question_type', 'open_text');

        return view('exam.result', [
            'examSession' => $examSession,
            'questions' => $questions,
            'wrong' => $wrong,
            'unanswered' => $unanswered,
            'topicBreakdown' => $topicBreakdown,
            'openTextCount' => $openTextQuestions->count(),
            'openTextCorrect' => $openTextQuestions->where('correct', true)->count(),
            'partResults' => $examSession->effectiveRuleSet()->partResults($questions),
        ]);
    }

    private function authorizeSession(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, ExamSession $examSession): void
    {
        abort_unless($examSession->user_id === $request->user()->id, 403);

        // Bug-Review B11: ohne diese Prüfung ließ sich eine laufende Prüfung
        // beliebig fortsetzen (und auch abgeschlossene Ergebnisse weiter
        // einsehen), selbst nachdem die Kursberechtigung entzogen wurde --
        // genau wie bei der Fortschrittsseite (ProgressController) gilt der
        // Zugriff auf einen Kurs nur, solange ein aktives Entitlement besteht.
        $course = $examSession->course;
        abort_unless($course && $entitlements->hasAccess($tenantContext->tenant(), $request->user(), $course), 403, 'Dein Zugang zu diesem Kurs ist abgelaufen oder nicht freigeschaltet.');
    }
}
