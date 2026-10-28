<?php

namespace App\Http\Controllers;

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamSession;
use App\Models\VideoLesson;
use App\Services\EntitlementService;
use App\Services\ExamEvaluationService;
use App\Services\LearningService;
use App\Support\TenantContext;
use App\Support\VideoCourseLessons;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Zu den Fragen"-Quiz nach einem Video (siehe video_lesson_question-Pivot,
 * im Superadmin über VideoLessonController gepflegt). Antworten laufen über
 * dieselbe LearningService::recordAttempt()-Learning-Loop wie Smart-Learning
 * und Prüfungssimulation, damit Fortschritt/Mastery konsistent einfließen.
 */
class VideoQuizController extends Controller
{
    public function show(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, VideoLesson $lesson, int $position): View
    {
        abort_unless($entitlements->hasAccess($tenantContext->tenant(), $request->user(), $course), 403);
        abort_unless($lesson->module->course_id === $course->id, 404);

        $questions = $this->resolveQuestions($lesson, $request);
        abort_if($questions->isEmpty(), 404);
        abort_unless($position >= 1 && $position <= $questions->count(), 404);

        $revision = $questions[$position - 1]->publishedRevision();
        abort_if(! $revision, 404, 'Für diese Frage liegt keine veröffentlichte Fassung vor.');
        $revision->load('answers', 'media');

        $moduleIds = $this->parseModuleIds($request);

        // Bug-Review N19: die soeben abgegebene Antwort wird nur über
        // genau diese eine Flash-Nachricht (gültig für genau den nächsten
        // Request) angezeigt, siehe answer(). Ein F5 auf dieser Seite ist
        // damit ein harmloses GET, das die Frage wieder unbeantwortet
        // zeigt, statt -- wie vorher, als answer() die Rückmeldung direkt
        // als POST-Antwort rendertete -- dieselbe Antwort erneut
        // abzuschicken.
        $justAnswered = $request->session()->get('videoQuizAnswered');
        $answered = $justAnswered !== null
            && $justAnswered['lessonId'] === $lesson->id
            && $justAnswered['questionId'] === $revision->question_id;

        return view('video.quiz', [
            'course' => $course,
            'lesson' => $lesson,
            'moduleIds' => $moduleIds,
            'questionsParam' => $request->query('questions'),
            'position' => $position,
            'total' => $questions->count(),
            'revision' => $revision,
            'answered' => $answered,
            'correct' => $answered ? $justAnswered['correct'] : null,
            'selectedAnswerId' => $answered ? $justAnswered['selectedAnswerId'] : null,
            'selectedAnswerIds' => $answered ? $justAnswered['selectedAnswerIds'] : null,
        ]);
    }

    public function answer(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, LearningService $learningService, ExamEvaluationService $examEvaluation, CourseDefinition $course, VideoLesson $lesson, int $position): Response
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403);
        abort_unless($lesson->module->course_id === $course->id, 404);

        $questions = $this->resolveQuestions($lesson, $request);
        abort_unless($position >= 1 && $position <= $questions->count(), 404);

        $revision = $questions[$position - 1]->publishedRevision();
        abort_if(! $revision, 404);

        // Bug-Review N13: viele Fragen aus dem Katalog stecken unverändert
        // auch in amtlichen Prüfungsbögen. Ohne diese Prüfung konnte ein
        // Lerner während einer laufenden (noch nicht abgegebenen) Prüfung
        // dieselbe Frage im Video-Quiz aufrufen und sich die dort sofort
        // angezeigte richtige Antwort verraten lassen.
        //
        // Bug-Review (Hoch, Folgefehler von LearningController): dieselbe
        // Prüfung fragte bisher nur status='running' ab, ohne die Zeit zu
        // berücksichtigen -- eine Prüfungssession bleibt in der DB aber so
        // lange "running", bis sie ausgewertet wird (ExamController tut das
        // nur, wenn der Nutzer die Prüfungsseite selbst erneut aufruft).
        // Eine zeitlich abgelaufene, aber liegen gelassene Prüfung sperrte
        // dadurch jede ihrer Fragen hier PERMANENT, auch lange nach Ablauf
        // der Prüfungszeit. Eine abgelaufene Session wird deshalb jetzt
        // genau wie in ExamController sofort ausgewertet statt weiterhin
        // als blockierend zu gelten.
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

            abort(409, 'Diese Frage ist Teil einer laufenden Prüfung und kann hier aktuell nicht beantwortet werden.');
        }

        $validated = $request->validate([
            'answer_id' => ['nullable', 'uuid'],
            'answer_ids' => ['nullable', 'array'],
            'answer_ids.*' => ['uuid'],
            'known' => ['nullable', 'boolean'],
        ]);

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
            $tenant, $user, $revision, $selectedAnswer, 'video_quiz', $course, null, $selectedAnswers, $selfAssessedKnown
        );

        // Die Auswertungsseite liest den Ausgang je Frage aus der Session
        // statt aus der Attempt-Historie: Postgres vergibt created_at pro
        // Transaktion, zwei schnell aufeinanderfolgende Antworten können
        // also denselben Zeitstempel erhalten und wären dann nicht
        // zuverlässig nach "zuletzt beantwortet" sortierbar.
        $request->session()->put(
            "video_quiz.{$lesson->id}.{$revision->question_id}",
            [
                'selectedAnswerId' => $attempt->selected_answer_id,
                'selectedAnswerIds' => $attempt->selected_answer_ids,
                'correct' => $attempt->correct,
            ]
        );

        // Bug-Review N19: diese Methode rendertete die Antwort-Rückmeldung
        // bisher direkt als Antwort auf den POST -- ein F5 (Browser:
        // "Formular erneut senden?") sendete die Antwort dann noch einmal
        // und löste recordAttempt() erneut aus. Post/Redirect/Get wie bei
        // ExamController::answer(): der Browser landet nach dem POST auf
        // einem GET (show()), ein F5 dort wiederholt nur das (harmlose) GET.
        return redirect()->route('video.quiz.show', array_filter([
            'course' => $course,
            'lesson' => $lesson,
            'position' => $position,
            'questions' => $request->query('questions'),
            'kapitel' => $request->query('kapitel'),
        ]))->with('videoQuizAnswered', [
            'lessonId' => $lesson->id,
            'questionId' => $revision->question_id,
            'correct' => $attempt->correct,
            'selectedAnswerId' => $attempt->selected_answer_id,
            'selectedAnswerIds' => $attempt->selected_answer_ids,
        ]);
    }

    /** Übersicht aller Fragen des (ggf. gefilterten) Durchlaufs mit der
     * jeweils letzten Antwort -- Endpunkt des "Auswertung anzeigen"-Buttons
     * nach der letzten Frage. */
    public function result(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, VideoLesson $lesson): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403);
        abort_unless($lesson->module->course_id === $course->id, 404);

        $questions = $this->resolveQuestions($lesson, $request);
        abort_if($questions->isEmpty(), 404);

        $answers = $request->session()->get("video_quiz.{$lesson->id}", []);

        $reviews = $questions->map(function (ContentQuestion $question) use ($answers) {
            $revision = $question->publishedRevision();
            $revision?->load('answers');
            $answer = $answers[$question->id] ?? null;

            return [
                'questionId' => $question->id,
                'revision' => $revision,
                'selectedAnswerId' => $answer['selectedAnswerId'] ?? null,
                'selectedAnswerIds' => $answer['selectedAnswerIds'] ?? null,
                'correct' => (bool) ($answer['correct'] ?? false),
            ];
        })->filter(fn (array $r) => $r['revision'] !== null)->values();

        $moduleIds = $this->parseModuleIds($request);

        return view('video.quiz-result', [
            'course' => $course,
            'lesson' => $lesson,
            'moduleIds' => $moduleIds,
            'reviews' => $reviews,
            'correctCount' => $reviews->where('correct', true)->count(),
            'total' => $reviews->count(),
            'wrongQuestionIds' => $reviews->reject(fn (array $r) => $r['correct'])->pluck('questionId')->values(),
            'next' => $this->nextLesson($course, $lesson, $moduleIds),
        ]);
    }

    /** @return Collection<int, ContentQuestion> */
    private function resolveQuestions(VideoLesson $lesson, Request $request): Collection
    {
        // Bug-Review B12 (Folgefehler): eine im Superadmin deaktivierte
        // Frage wurde hier -- im Gegensatz zu Smarttrainer und Prüfung --
        // trotzdem noch im Video-Quiz ausgespielt.
        $activeQuestions = $lesson->questions->where('active', true);

        $raw = $request->query('questions');

        // Bug-Review (Query-Parameter-Validierung): ?questions=a,b wird als
        // String erwartet -- ein manipulierter Request mit
        // ?questions[]=a&questions[]=b liefert von Laravel aus stattdessen
        // ein Array, das explode(',', ...) mit einem TypeError scheitern
        // ließ, unbehandelt mit HTTP 500.
        if (! is_string($raw) || $raw === '') {
            return $activeQuestions->values();
        }

        $ids = array_values(array_filter(explode(',', $raw)));

        return $activeQuestions->whereIn('id', $ids)
            ->sortBy(fn ($question) => array_search($question->id, $ids, true))
            ->values();
    }

    private function parseModuleIds(Request $request): ?array
    {
        $raw = $request->query('kapitel');

        // Bug-Review (Query-Parameter-Validierung): derselbe Fehler wie bei
        // resolveQuestions() oben -- ein manipulierter Request mit
        // ?kapitel[]=a&kapitel[]=b liefert ein Array statt eines Strings,
        // das explode(',', ...) mit einem TypeError scheitern ließ
        // (VideoCourseController::parseModuleIds() wurde bereits dagegen
        // abgesichert, diese zweite, eigene Kopie hier noch nicht).
        return is_string($raw) && $raw !== '' ? array_values(array_filter(explode(',', $raw))) : null;
    }

    private function nextLesson(CourseDefinition $course, VideoLesson $lesson, ?array $moduleIds): ?VideoLesson
    {
        $lessons = VideoCourseLessons::ordered($course, $moduleIds);
        $currentIndex = $lessons->search(fn ($l) => $l->id === $lesson->id);

        return $currentIndex === false ? null : $lessons->get($currentIndex + 1);
    }
}
