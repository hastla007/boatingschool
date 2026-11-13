<?php

namespace App\Services;

use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\ExamSessionNavigationAnswer;
use App\Models\ExamSessionQuestion;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Prüfungssimulation starten bzw. die laufende fortsetzen -- gemeinsam für
 * die Web-Oberfläche (ExamController) und die Mobile-API. Zugang und
 * Regelwerk prüft der Aufrufer.
 */
class ExamSessionStarter
{
    public function __construct(private ExamEvaluationService $examEvaluation) {}

    /** Fester Prüfungsbogen. */
    public function startPaper(Tenant $tenant, User $user, CourseDefinition $course, ExamRuleSet $ruleSet, ExamPaper $paper): ExamSession
    {
        return DB::transaction(function () use ($tenant, $user, $course, $ruleSet, $paper) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$tenant->id.':'.$user->id.':'.$paper->id]);

            $running = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->where('paper_id', $paper->id)->where('status', 'running')->first();

            if ($running && $running->stillOpen($this->examEvaluation->timeExpired($running))) {
                return $running;
            }

            if ($running) {
                // Eine abgelaufene, aber noch nicht ausgewertete Session
                // blockierte bisher den Neustart: der erste Klick auf
                // "Jetzt starten" führte (über show()) nur zum alten
                // Ergebnis statt direkt zu einem neuen Versuch. Jetzt wird
                // sie hier sofort abgeschlossen, bevor der neue Versuch
                // angelegt wird.
                $this->examEvaluation->evaluate($running);
            }

            $session = ExamSession::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'course_id' => $course->id,
                'rule_set_id' => $ruleSet->id,
                'rule_snapshot' => ExamSession::snapshotOf($ruleSet),
                'paper_id' => $paper->id,
                'status' => 'running',
                'started_at' => now(),
            ]);

            $position = 1;
            $rows = [];
            // Bug-Review (BB16-Rest: feste Bögen ignorierten "Gültig ab/bis"):
            // wie die deaktivierte Frage (B12) wird auch eine Frage außerhalb
            // ihres Gültigkeitsfensters nicht ausgespielt.
            $paper->loadMissing('paperQuestions.question');
            $validQuestionIds = ContentQuestion::currentlyValidIds($paper->paperQuestions->pluck('question'))->flip();
            // Veröffentlichte Fassungen aller Bogenfragen in einer Abfrage
            // (statt einer je Frage) -- gleiche Auswahl wie publishedRevision().
            $revisionIds = $this->publishedRevisionIds($paper->paperQuestions->pluck('question_id'));
            foreach ($paper->paperQuestions as $paperQuestion) {
                // Bug-Review B12 (Folgefehler): eine nach dem Anlegen des
                // Bogens im Superadmin deaktivierte Frage (content_question.
                // active) wurde hier -- im Gegensatz zur zufällig
                // zusammengestellten Prüfung in start() -- trotzdem noch
                // ausgespielt. Positionen werden deshalb neu durchnummeriert,
                // statt Lücken zu lassen.
                if (! $validQuestionIds->has($paperQuestion->question_id)) {
                    continue;
                }

                $revisionId = $revisionIds->get($paperQuestion->question_id);
                abort_if(! $revisionId, 404, 'Für eine Frage dieses Bogens liegt keine veröffentlichte Fassung vor.');

                $rows[] = [
                    'tenant_id' => $session->tenant_id,
                    'exam_session_id' => $session->id,
                    'question_id' => $paperQuestion->question_id,
                    'revision_id' => $revisionId,
                    'position' => $position++,
                ];
            }
            // Alle Gültigkeitsfenster abgelaufen bzw. Fragen deaktiviert: keine
            // leere Prüfung anlegen, die als „nicht bestanden“ zählt.
            abort_if($rows === [], 409, 'Für diesen Prüfungsbogen sind derzeit keine Fragen verfügbar. Bitte wende dich an deine Bootsschule.');
            // Alle Fragen in einem INSERT.
            ExamSessionQuestion::insert($rows);

            // SBF See: Navigationsaufgabe mit derselben Nummer wie der Bogen
            // als eigener Prüfungsteil (mindestens 7 von 9 Punkten).
            $this->attachNavigationTask($session, $ruleSet, $course, $paper->paper_number);

            return $session;
        });
    }

    /** Zufällig zusammengestellte Prüfung (Kurse ohne feste Bögen). */
    public function startRandom(Tenant $tenant, User $user, CourseDefinition $course, ExamRuleSet $ruleSet): ExamSession
    {
        return DB::transaction(function () use ($tenant, $user, $course, $ruleSet) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$tenant->id.':'.$user->id.':'.$course->id.':random-exam']);

            $running = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->where('course_id', $course->id)->whereNull('paper_id')->where('status', 'running')->first();

            if ($running && $running->stillOpen($this->examEvaluation->timeExpired($running))) {
                return $running;
            }

            if ($running) {
                $this->examEvaluation->evaluate($running);
            }

            $session = ExamSession::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'course_id' => $course->id,
                'rule_set_id' => $ruleSet->id,
                'rule_snapshot' => ExamSession::snapshotOf($ruleSet),
                'status' => 'running',
                'started_at' => now(),
            ]);

            $position = 1;
            $rows = [];
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

                $revisionIds = $this->publishedRevisionIds($questions->pluck('id'));
                foreach ($questions as $question) {
                    // Gleichzeitig ausgewählte Frage in zwei Blueprints: nur einmal.
                    if (isset($rows[$question->id])) {
                        continue;
                    }
                    $rows[$question->id] = [
                        'tenant_id' => $session->tenant_id,
                        'exam_session_id' => $session->id,
                        'question_id' => $question->id,
                        'revision_id' => $revisionIds->get($question->id),
                        'position' => $position++,
                    ];
                }
            }
            if ($rows !== []) {
                ExamSessionQuestion::insert(array_values($rows));
            }

            // Ohne gültige Fragen (z. B. alle Fragen eines Moduls deaktiviert)
            // gäbe es eine leere Prüfung, die als „nicht bestanden“ zählt.
            abort_if($position === 1, 409, 'Für diesen Kurs sind derzeit keine Prüfungsfragen verfügbar. Bitte wende dich an deine Bootsschule.');

            return $session;
        });
    }

    private function attachNavigationTask(ExamSession $session, ExamRuleSet $ruleSet, CourseDefinition $course, int $taskNumber): void
    {
        if ($ruleSet->navigationRule() === null) {
            return;
        }
        $task = $course->navigationTasks()->where('task_number', $taskNumber)->with('questions')->first();
        // Das Regelwerk verlangt einen Navigationsteil: ohne (vollständige)
        // Aufgabe wäre die Prüfung entweder ohne Navigation bestehbar oder nie.
        abort_if(! $task || $task->questions->count() < $ruleSet->navigationRule()['min_points'], 409,
            'Die Navigationsaufgabe zu diesem Prüfungsbogen fehlt oder ist unvollständig. Bitte wende dich an deine Bootsschule.');
        $session->update(['navigation_task_id' => $task->id, 'navigation_task_snapshot' => $task->only(['task_number', 'scenario_text', 'hint', 'chart_image_path', 'chart_caption'])]);
        $model = new ExamSessionNavigationAnswer;
        ExamSessionNavigationAnswer::insert($task->questions->sortBy('question_number')->values()->map(fn ($question, $i) => [
            'id' => $model->newUniqueId(),
            'tenant_id' => $session->tenant_id,
            'exam_session_id' => $session->id,
            'navigation_question_id' => $question->id,
            'position' => $i + 1,
            'question_number' => $question->question_number,
            'question_text' => $question->question_text,
            'answer_text' => $question->answer_text,
        ])->all());
    }

    /**
     * Neueste veröffentlichte Fassung je Frage (wie ContentQuestion::
     * publishedRevision()), für viele Fragen in einer Abfrage.
     *
     * @return Collection<string, string> question_id => revision_id
     */
    private function publishedRevisionIds(Collection $questionIds): Collection
    {
        return ContentQuestionRevision::whereIn('question_id', $questionIds->unique()->values())
            ->where('editorial_status', 'published')
            ->orderBy('question_id')->orderByDesc('revision_no')
            ->get(['id', 'question_id'])
            ->unique('question_id')
            ->pluck('id', 'question_id');
    }
}
