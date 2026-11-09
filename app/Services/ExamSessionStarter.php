<?php

namespace App\Services;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\ExamSessionNavigationAnswer;
use App\Models\ExamSessionQuestion;
use App\Models\Tenant;
use App\Models\User;
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
            // Bug-Review (BB16-Rest: feste Bögen ignorierten "Gültig ab/bis"):
            // wie die deaktivierte Frage (B12) wird auch eine Frage außerhalb
            // ihres Gültigkeitsfensters nicht ausgespielt.
            $paper->loadMissing('paperQuestions.question');
            $validQuestionIds = ContentQuestion::currentlyValidIds($paper->paperQuestions->pluck('question'))->flip();
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

                $revision = $paperQuestion->question->publishedRevision();
                abort_if(! $revision, 404, 'Für eine Frage dieses Bogens liegt keine veröffentlichte Fassung vor.');

                ExamSessionQuestion::create([
                    'exam_session_id' => $session->id,
                    'question_id' => $paperQuestion->question_id,
                    'revision_id' => $revision->id,
                    'position' => $position++,
                ]);
            }

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
        if (! $task || $task->questions->isEmpty()) {
            return;
        }
        $session->update(['navigation_task_id' => $task->id, 'navigation_task_snapshot' => $task->only(['task_number', 'scenario_text', 'hint', 'chart_image_path', 'chart_caption'])]);
        foreach ($task->questions->sortBy('question_number')->values() as $i => $question) {
            ExamSessionNavigationAnswer::create([
                'tenant_id' => $session->tenant_id,
                'exam_session_id' => $session->id,
                'navigation_question_id' => $question->id,
                'position' => $i + 1,
                'question_number' => $question->question_number,
                'question_text' => $question->question_text,
                'answer_text' => $question->answer_text,
            ]);
        }
    }
}
