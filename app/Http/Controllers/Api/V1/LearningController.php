<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Api\CourseAccess;
use App\Api\Present;
use App\Api\QuestionPresenter;
use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\ContentQuestionRevision;
use App\Models\Favorite;
use App\Models\FavoriteCollection;
use App\Models\FavoriteCollectionItem;
use App\Models\Progress;
use App\Services\LearningFlow;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Smarttrainer der App: dieselbe Auswahl und Bewertung wie im Web
 * (LearningFlow). „Gefestigt“ = 3 richtige Antworten in Folge; ein Fehler
 * setzt die Serie zurück, falsch beantwortete Fragen kommen wieder dran.
 */
class LearningController extends Controller
{
    public function __construct(
        private TenantContext $tenantContext,
        private CourseAccess $access,
        private LearningFlow $flow,
    ) {}

    public function next(Request $request, string $courseId): JsonResponse|Response
    {
        $validated = $request->validate([
            'mode' => ['nullable', 'string', 'in:'.implode(',', LearningFlow::MODES)],
            'module_id' => ['nullable', 'uuid'],
            'topic' => ['nullable', 'string', 'max:255'],
            'question_ids' => ['nullable', 'string', 'max:4000'],
            'collection_id' => ['nullable', 'uuid'],
        ]);
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId);
        $mode = $validated['mode'] ?? 'smarttrainer';
        $questionIds = isset($validated['question_ids']) ? array_values(array_filter(explode(',', $validated['question_ids']))) : null;
        // Review-Runde 13: eine Favoriten-Sammlung üben.
        if (! empty($validated['collection_id'])) {
            $collection = FavoriteCollection::where('id', $validated['collection_id'])->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)->where('course_id', $course->id)->first();
            if (! $collection) {
                throw ApiProblem::notFound('Sammlung');
            }
            $mode = 'favorites';
            $questionIds = FavoriteCollectionItem::where('tenant_id', $tenant->id)->where('favorite_collection_id', $collection->id)
                ->pluck('question_id')->unique()->values()->all();
        }

        $next = $this->flow->nextQuestion($tenant, $user, $course, $mode, $validated['topic'] ?? null, $validated['module_id'] ?? null, $questionIds);
        if (! $next) {
            return response()->noContent();
        }

        $revision = $next['question']->publishedRevision();
        if (! $revision) {
            return response()->noContent();
        }
        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('question_id', $revision->question_id)->first();
        $moduleId = $course->modules->first(fn ($m) => $m->questions->contains('id', $revision->question_id))?->id;
        $question = QuestionPresenter::question($revision, $moduleId, $user->id);
        if ($revision->question_type === 'open_text') {
            // Offene Frage im Lernmodus: Musterantwort zum Aufdecken, danach
            // schätzt die Person selbst ein (known: true/false).
            $question['model_answer'] = $revision->correctAnswer()?->answer_text;
        }

        return response()->json(['data' => [
            'question' => $question,
            'answer_token' => $revision->id,
            'mode' => $mode,
            'selection_reason' => $next['reason'] ?? null,
            'is_favorite' => Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->where('context', Favorite::CONTEXT_SMART_LEARNING)->where('question_id', $revision->question_id)->exists(),
            'learning_state' => $progress?->learning_state ?? 'neu',
            'streak' => ['current' => min((int) ($progress?->current_streak ?? 0), 3), 'required' => 3],
        ]]);
    }

    /**
     * WP-compatible reveal route for open questions. The UUID token is the delivered revision ID;
     * course access and course membership are rechecked on every request.
     */
    public function reveal(Request $request, string $courseId): JsonResponse
    {
        $validated = $request->validate(['answer_token' => ['required', 'uuid']]);
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId);
        $revision = ContentQuestionRevision::with('answers')->find($validated['answer_token']);
        if (! $revision || $revision->editorial_status !== 'published') {
            throw ApiProblem::notFound('Frage');
        }

        $belongsToCourse = DB::table('course_module')
            ->join('module_content', 'module_content.module_id', '=', 'course_module.module_id')
            ->where('course_module.course_id', $course->id)
            ->where('module_content.question_id', $revision->question_id)
            ->exists();
        if (! $belongsToCourse) {
            throw ApiProblem::notFound('Frage');
        }
        if ($revision->question_type !== 'open_text') {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, [
                'answer_token' => ['Nur offene Fragen haben eine aufzudeckende Musterantwort.'],
            ]);
        }

        return response()->json(['data' => ['model_answer' => $revision->correctAnswer()?->answer_text]]);
    }

    public function submit(Request $request, string $courseId): JsonResponse
    {
        $validated = $request->validate([
            'question_id' => ['bail', 'required', 'uuid'],
            'revision_id' => ['bail', 'required', 'uuid'],
            'mode' => ['nullable', 'string', 'in:'.implode(',', LearningFlow::MODES)],
            'selected_answer_ids' => ['nullable', 'array', 'max:20'],
            'selected_answer_ids.*' => ['uuid', 'distinct'],
            'known' => ['nullable', 'boolean'],
            'client_answered_at' => ['nullable', 'date'],
        ]);
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId);

        $revision = ContentQuestionRevision::find($validated['revision_id']);
        if (! $revision || $revision->question_id !== $validated['question_id']) {
            throw ApiProblem::notFound('Frage');
        }
        $selected = $validated['selected_answer_ids'] ?? [];
        if ($revision->question_type === 'open_text' && ! array_key_exists('known', $validated)) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['known' => ['Bitte angeben, ob du die Antwort wusstest.']]);
        }
        if ($revision->question_type !== 'open_text' && $selected === []) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['selected_answer_ids' => ['Bitte eine Antwort auswählen.']]);
        }
        if ($revision->question_type !== 'multi_choice' && $revision->question_type !== 'open_text' && count($selected) > 1) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['selected_answer_ids' => ['Bei dieser Frage ist genau eine Antwort richtig.']]);
        }

        try {
            $attempt = $this->flow->submitAttempt($tenant, $user, $course, [
                'revision_id' => $revision->id,
                'answer_id' => $revision->question_type === 'multi_choice' ? null : ($selected[0] ?? null),
                'answer_ids' => $revision->question_type === 'multi_choice' ? $selected : null,
                'known' => $validated['known'] ?? null,
                'mode' => $validated['mode'] ?? 'smarttrainer',
            ]);
        } catch (HttpException $e) {
            // Review-Runde 13: eigene Codes statt allgemeinem CONFLICT/404.
            throw match ($e->getStatusCode()) {
                409 => new ApiProblem(409, 'EXAM_QUESTION_LOCKED', 'Frage gerade in der Prüfung', $e->getMessage()),
                404 => str_contains($e->getMessage(), 'Antwort')
                    ? new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['selected_answer_ids' => ['Unbekannte Antwort.']])
                    : ApiProblem::notFound('Frage'),
                default => $e,
            };
        }
        // created_at kommt aus der Datenbank (Standardwert).
        $attempt->refresh();

        return response()->json(['data' => $this->result($attempt)], 201);
    }

    public function showAttempt(Request $request, string $courseId, string $attemptId): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId);
        $attempt = Attempt::where('id', $attemptId)->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('course_id', $course->id)->first();
        if (! $attempt) {
            throw ApiProblem::notFound('Versuch');
        }

        return response()->json(['data' => $this->result($attempt)]);
    }

    private function result(Attempt $attempt): array
    {
        $revision = ContentQuestionRevision::with('answers')->findOrFail($attempt->revision_id);
        $progress = Progress::where('tenant_id', $attempt->tenant_id)->where('user_id', $attempt->user_id)->where('question_id', $attempt->question_id)->first();
        // Offene Fragen: keine Auswahl (intern steht dort die Musterantwort).
        $selected = $revision->question_type === 'open_text' ? [] : ($attempt->selected_answer_ids ?: array_filter([$attempt->selected_answer_id]));

        return [
            'attempt_id' => $attempt->id,
            'question_id' => $attempt->question_id,
            'correct' => (bool) $attempt->correct,
            'selected_answer_ids' => array_values($selected),
            ...QuestionPresenter::solution($revision, (bool) $attempt->correct),
            'learning_state' => $progress?->learning_state ?? 'neu',
            'streak' => ['current' => min((int) ($progress?->current_streak ?? 0), 3), 'required' => 3],
            'completed' => ($progress?->learning_state ?? null) === 'gefestigt',
            'progress' => [
                'mastery_score' => (float) ($progress?->mastery_score ?? 0),
                'current_streak' => (int) ($progress?->current_streak ?? 0),
            ],
            'answered_at' => Present::time($attempt->created_at),
        ];
    }
}
