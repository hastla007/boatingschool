<?php

namespace App\Http\Controllers;

use App\Models\ContentAnswer;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use App\Models\ExamSession;
use App\Models\ExamSessionQuestion;
use App\Models\Favorite;
use App\Models\Progress;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\ExamEvaluationService;
use App\Support\ComboExamGroups;
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
        // Bug-Review B5 (Folgefehler): startPaper() lehnt einen Versuch für
        // ein nicht freigegebenes Regelwerk bereits mit 409 ab -- diese
        // Übersicht zeigte die "Bogen N"-Start-Buttons aber unverändert an,
        // ein Klick führte also ins Leere statt den Kurs klar als noch
        // nicht freigegeben zu kennzeichnen (analog zu exam/intro.blade.php).
        $ruleSet = $course->activeExamRuleSet();
        $isVerified = $ruleSet && $ruleSet->isVerified();

        $paperIds = $papers->pluck('id');

        $latestEvaluatedSessions = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->whereIn('paper_id', $paperIds)
            ->where('status', 'evaluated')
            ->orderByDesc('submitted_at')
            ->get()
            ->unique('paper_id')
            ->keyBy('paper_id');

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
            ->where('content_question.active', true)
            ->selectRaw('exam_paper_id, count(*) as total_count')
            ->groupBy('exam_paper_id')
            ->pluck('total_count', 'exam_paper_id');

        $correctBySession = ExamSessionQuestion::whereIn('exam_session_id', $latestEvaluatedSessions->pluck('id'))
            ->where('correct', true)
            ->selectRaw('exam_session_id, count(*) as correct_count')
            ->groupBy('exam_session_id')
            ->pluck('correct_count', 'exam_session_id');

        $paperStats = $papers->map(function ($paper) use ($latestEvaluatedSessions, $course, $totalsByPaper, $correctBySession) {
            $session = $latestEvaluatedSessions->get($paper->id);
            $comboInfo = ComboExamGroups::infoFor($course->code, $paper->paper_number);

            return [
                'paper' => $paper,
                'attempted' => (bool) $session,
                'percent' => $session ? (int) round($session->score * 100) : 0,
                'correct' => $session ? $correctBySession->get($session->id, 0) : 0,
                'total' => $totalsByPaper->get($paper->id, 0),
                'group' => $comboInfo['label'] ?? null,
                'displayNumber' => $comboInfo['displayNumber'] ?? $paper->paper_number,
            ];
        });

        $attemptedStats = $paperStats->filter(fn ($s) => $s['attempted']);
        $overallPercent = $attemptedStats->isNotEmpty() ? (int) round($attemptedStats->avg('percent')) : 0;

        // Favoriten sind kursgebunden: nur Fragen zählen, die tatsächlich zu
        // diesem Kurs gehören, damit jeder Kurs seine eigenen Favoriten hat.
        $courseQuestionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique();
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
        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403);
        abort_unless($paper->course_id === $course->id, 404);

        $ruleSet = $course->activeExamRuleSet();
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
        return DB::transaction(function () use ($tenant, $user, $course, $ruleSet, $paper, $examEvaluation) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$tenant->id.':'.$user->id.':'.$paper->id]);

            $running = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->where('paper_id', $paper->id)->where('status', 'running')->first();

            if ($running && ! $examEvaluation->timeExpired($running)) {
                return redirect()->route('exam.show', $running);
            }

            if ($running) {
                // Eine abgelaufene, aber noch nicht ausgewertete Session
                // blockierte bisher den Neustart: der erste Klick auf
                // "Jetzt starten" führte (über show()) nur zum alten
                // Ergebnis statt direkt zu einem neuen Versuch. Jetzt wird
                // sie hier sofort abgeschlossen, bevor der neue Versuch
                // angelegt wird.
                $examEvaluation->evaluate($running);
            }

            $session = ExamSession::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'course_id' => $course->id,
                'rule_set_id' => $ruleSet->id,
                'paper_id' => $paper->id,
                'status' => 'running',
                'started_at' => now(),
            ]);

            $position = 1;
            foreach ($paper->paperQuestions as $paperQuestion) {
                // Bug-Review B12 (Folgefehler): eine nach dem Anlegen des
                // Bogens im Superadmin deaktivierte Frage (content_question.
                // active) wurde hier -- im Gegensatz zur zufällig
                // zusammengestellten Prüfung in start() -- trotzdem noch
                // ausgespielt. Positionen werden deshalb neu durchnummeriert,
                // statt Lücken zu lassen.
                if (! $paperQuestion->question->active) {
                    continue;
                }

                $revision = $paperQuestion->question->publishedRevision();
                abort_if(! $revision, 404, 'Für eine Frage dieses Bogens liegt keine veröffentlichte Fassung vor.');

                ExamSessionQuestion::create([
                    'exam_session_id' => $session->id,
                    'question_id' => $paperQuestion->question_id,
                    'revision_id' => $revision->id,
                    'position' => $position++,
                ]);
            }

            return redirect()->route('exam.show', $session);
        });
    }

    public function start(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, ExamEvaluationService $examEvaluation, CourseDefinition $course): Response
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();
        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403);

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
        abort_if($course->examPapers()->exists(), 409, 'Für diesen Kurs gibt es feste Prüfungsbögen -- die zufällig zusammengestellte Prüfung ist hier nicht verfügbar.');

        $ruleSet = $course->activeExamRuleSet();
        abort_unless($ruleSet && $ruleSet->isVerified(), 409, 'Für diesen Kurs liegt noch kein fachlich freigegebenes Prüfungsregelwerk vor.');

        // Bug-Review N20: derselbe Doppelklick-Fehler wie B4 (startPaper()),
        // nur für die zufällig zusammengestellte Prüfung ohne Bogen -- dafür
        // gab es bisher weder einen Advisory-Lock noch einen Unique-Index.
        // Ein Doppelklick auf "Zufallsprüfung starten" konnte zwei parallele
        // "running"-Sessions für denselben Kurs anlegen. Der Unique-Index
        // aus Migration 000466 sichert das zusätzlich auf Datenbankebene ab.
        return DB::transaction(function () use ($tenant, $user, $course, $ruleSet, $examEvaluation) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$tenant->id.':'.$user->id.':'.$course->id.':random-exam']);

            $running = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->where('course_id', $course->id)->whereNull('paper_id')->where('status', 'running')->first();

            if ($running && ! $examEvaluation->timeExpired($running)) {
                return redirect()->route('exam.show', $running);
            }

            if ($running) {
                $examEvaluation->evaluate($running);
            }

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
                // Bug-Review B12: eine im Superadmin deaktivierte Frage
                // (content_question.active) konnte hier trotzdem in eine
                // zufällig zusammengestellte Prüfung geraten.
                // Bug-Review BB16 ("Gültig ab"/"Gültig bis" wirkungslos):
                // dieselbe Lücke bestand für valid_from/valid_to.
                $questions = $blueprint->module->questions()
                    ->currentlyValid()
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

            return redirect()->route('exam.show', $session);
        });
    }

    public function show(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, ExamEvaluationService $examEvaluation, ExamSession $examSession): View|Response
    {
        $this->authorizeSession($request, $tenantContext, $entitlements, $examSession);

        if ($examSession->status === 'evaluated') {
            return redirect()->route('exam.result', $examSession);
        }

        if ($examEvaluation->timeExpired($examSession)) {
            return $this->finish($request, $tenantContext, $entitlements, $examEvaluation, $examSession);
        }

        $current = $examSession->questions()->whereNull('answered_at')->orderBy('position')->with('revision.answers', 'revision.media')->first();

        if (! $current) {
            return $this->finish($request, $tenantContext, $entitlements, $examEvaluation, $examSession);
        }

        $total = $examSession->questions()->count();
        $answered = $examSession->questions()->whereNotNull('answered_at')->count();
        // Bug-Review B23/B24: diffInSeconds() liefert einen float mit
        // Mikrosekunden-Bruchteil -- das löste beim Rendern per gmdate()
        // eine Deprecation-Warnung aus ("Implicit conversion from float to
        // int loses precision"). (int) rundet hier zuverlässig ab.
        $remainingSeconds = $examSession->ruleSet->time_limit_seconds
            ? max(0, (int) ($examSession->ruleSet->time_limit_seconds - $examSession->started_at->diffInSeconds(now())))
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

        // Bug-Review (AA4: Prüfung nach Ablauf der Zeit zeigt englische
        // Fehlerseite): wer nach Zeitablauf noch antwortet, erreicht diese
        // Methode oft ein zweites Mal (Doppelklick, oder der Browser hatte
        // die Seite bereits neu geladen) -- die Session steht dann schon auf
        // "evaluated" (siehe timeExpired()-Zweig unten bzw. finish()). Statt
        // dafür bisher ein nacktes abort(409) ohne eigene errors/409.blade.php
        // zu zeigen (Laravels generische, englische Fallback-Fehlerseite),
        // landet der Lernende jetzt direkt beim längst fertigen Ergebnis --
        // genau das, was er nach Ablauf der Zeit sehen will.
        if ($examSession->status === 'evaluated') {
            return redirect()->route('exam.result', $examSession);
        }

        abort_if($examSession->status !== 'running', 409);

        // Serverseitig prüfen statt nur der Weiterleitung in show() zu
        // vertrauen (Sicherheitsreview M2): ohne diese Prüfung ließ sich nach
        // Ablauf der Zeit beliebig weiter antworten, solange die Session noch
        // auf "running" stand. Abgelaufen wird sofort ausgewertet und
        // abgeschlossen statt die Antwort noch anzunehmen.
        if ($examEvaluation->timeExpired($examSession)) {
            $examEvaluation->evaluate($examSession);

            return redirect()->route('exam.result', $examSession)
                ->with('status', 'Die Prüfungszeit ist abgelaufen -- hier ist dein Ergebnis.');
        }

        $validated = $request->validate([
            'position' => ['required', 'integer'],
            'answer_id' => ['nullable', 'uuid'],
            'answer_ids' => ['nullable', 'array'],
            'answer_ids.*' => ['uuid'],
            'known' => ['nullable', 'boolean'],
        ]);

        // whereNull('answered_at'): eine bereits beantwortete Frage lässt
        // sich über die reguläre UI (show() liefert immer die nächste
        // unbeantwortete Frage) nicht erneut erreichen -- nur über einen
        // gezielt nachgebauten Request mit alter position. Das wird hier
        // unterbunden (Sicherheitsreview M2).
        $sessionQuestion = $examSession->questions()->where('position', $validated['position'])
            ->whereNull('answered_at')->with('revision')->firstOrFail();
        $type = $sessionQuestion->revision->question_type;

        // Bug-Review (Zwei gleichzeitige Prüfungsauswertungen stören sich):
        // der abort_if()-Check oben liest den Status, BEVOR die Antwort
        // unten gespeichert wird -- lief währenddessen eine Auswertung
        // (finish() oder der Zeit-Timeout-Zweig oben, siehe
        // ExamEvaluationService::evaluate()) nebenläufig durch, konnte eine
        // Antwort noch auf eine Frage geschrieben werden, die bereits
        // bewertet wurde (correct stand damit auf dem Stand VOR dieser
        // Antwort fest, die Antwort selbst wirkte nie auf die Bewertung).
        // Die exam_session-Zeile wird deshalb jetzt gesperrt, bis eine
        // nebenläufige Auswertung committet hat, und der Status danach
        // erneut geprüft -- evaluate() sperrt dieselbe Zeile, die beiden
        // serialisieren sich also gegenseitig.
        DB::transaction(function () use ($examSession, $sessionQuestion, $type, $validated) {
            // DB::table() statt einer zweiten Eloquent-Abfrage auf dem
            // ExamSession-Model: vermeidet, dass der globale Tenant-Scope
            // (BelongsToTenant) dabei erneut ausgewertet wird (siehe
            // ExamEvaluationService::evaluate()).
            DB::table('exam_session')->where('id', $examSession->id)->lockForUpdate()->first();
            $examSession->refresh();
            abort_if($examSession->status !== 'running', 409);

            // Keine Sofortauflösung: correct wird erst bei finish() befüllt.
            if ($type === 'multi_choice') {
                abort_if(empty($validated['answer_ids']), 422, 'Bitte mindestens eine Antwort auswählen.');
                $answerIds = ContentAnswer::where('revision_id', $sessionQuestion->revision_id)
                    ->whereIn('id', $validated['answer_ids'])->pluck('id');
                abort_if($answerIds->count() !== count($validated['answer_ids']), 422);

                $sessionQuestion->update([
                    'selected_answer_id' => null,
                    'selected_answer_ids' => $answerIds->values()->all(),
                    'answered_at' => now(),
                ]);
            } elseif ($type === 'open_text') {
                abort_if(! array_key_exists('known', $validated) || $validated['known'] === null, 422, 'Bitte Selbsteinschätzung angeben.');

                // Bug-Review Oberfläche U9: self_assessed_known entscheidet
                // direkt über "correct" bei finish() (siehe ExamEvaluationService)
                // -- ohne diese Prüfung konnte ein per Devtools nachgebauter
                // Request mit known=1 jede offene Frage als gewusst zählen
                // lassen, ohne die Musterantwort je abgerufen zu haben.
                // reveal() setzt revealed_at erst, wenn die Antwort tatsächlich
                // ausgeliefert wurde.
                abort_unless($sessionQuestion->revealed_at, 422, 'Bitte zuerst die Musterantwort anzeigen.');

                $sessionQuestion->update([
                    'self_assessed_known' => (bool) $validated['known'],
                    'answered_at' => now(),
                ]);
            } else {
                abort_if(empty($validated['answer_id']), 422, 'Bitte eine Antwort auswählen.');
                $answer = ContentAnswer::where('id', $validated['answer_id'])
                    ->where('revision_id', $sessionQuestion->revision_id)
                    ->firstOrFail();

                $sessionQuestion->update([
                    'selected_answer_id' => $answer->id,
                    'answered_at' => now(),
                ]);
            }
        });

        return redirect()->route('exam.show', $examSession);
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
        abort_if($examSession->status !== 'running', 409);

        $validated = $request->validate([
            'position' => ['required', 'integer'],
        ]);

        $sessionQuestion = $examSession->questions()->where('position', $validated['position'])
            ->whereNull('answered_at')->with('revision')->firstOrFail();

        abort_unless($sessionQuestion->revision->question_type === 'open_text', 422);

        if (! $sessionQuestion->revealed_at) {
            $sessionQuestion->update(['revealed_at' => now()]);
        }

        return response()->json([
            'answer_text' => $sessionQuestion->revision->correctAnswer()?->answer_text,
        ]);
    }

    public function finish(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, ExamEvaluationService $examEvaluation, ExamSession $examSession): Response
    {
        $this->authorizeSession($request, $tenantContext, $entitlements, $examSession);

        if ($examSession->status === 'evaluated') {
            return redirect()->route('exam.result', $examSession);
        }

        $examEvaluation->evaluate($examSession);

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

        $topicBreakdown = $questions->groupBy(fn ($q) => $q->revision->topic ?? '—')
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
        abort_unless($course && $entitlements->hasAccess($tenantContext->tenant(), $request->user(), $course), 403, 'Für diesen Kurs liegt kein aktives Entitlement vor.');
    }
}
