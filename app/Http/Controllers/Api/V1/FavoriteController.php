<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Api\CourseAccess;
use App\Api\Present;
use App\Http\Controllers\Controller;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\Favorite;
use App\Models\FavoriteCollection;
use App\Models\FavoriteCollectionItem;
use App\Models\Progress;
use App\Services\FavoriteCollectionService;
use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Favoriten und Sammlungen (dieselben Daten wie im Web). Eine Frage kann in
 * mehreren Sammlungen liegen; jede Schule/jeder Kurs hat eine Standard-
 * Sammlung „Meine Favoriten“, die nicht gelöscht werden kann.
 */
class FavoriteController extends Controller
{
    public function __construct(
        private TenantContext $tenantContext,
        private CourseAccess $access,
        private FavoriteCollectionService $collections,
    ) {}

    public function index(Request $request, string $courseId): JsonResponse
    {
        $validated = $request->validate([
            'collection_id' => ['nullable', 'uuid'],
            'context' => ['nullable', 'in:'.Favorite::CONTEXT_SMART_LEARNING.','.Favorite::CONTEXT_EXAM],
        ]);
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId, 'favorites');

        $favorites = Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->whereIn('question_id', $this->access->accessibleQuestionIds($tenant, $user, $course))
            ->whereIn('question_id', ContentQuestion::query()->currentlyValid()->select('id'))
            ->when($validated['context'] ?? null, fn ($q, $context) => $q->where('context', $context))
            ->latest('created_at')->get();
        // Review-Runde 13: wie im Web Favoriten ohne Sammlung in die Standard-Sammlung.
        $this->collections->assignUnsortedToDefault($tenant, $user, $course, $favorites);
        // Kurzfassung je Frage für die Favoriten-Liste (ohne Lösung).
        $revisions = ContentQuestionRevision::whereIn('question_id', $favorites->pluck('question_id'))->where('editorial_status', 'published')
            ->get()->keyBy('question_id');
        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->whereIn('question_id', $favorites->pluck('question_id'))
            ->get()->keyBy('question_id');
        $items = FavoriteCollectionItem::where('tenant_id', $tenant->id)
            ->whereIn('favorite_collection_id', FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('course_id', $course->id)->select('id'))
            ->get()->groupBy(fn ($i) => $i->context.'|'.$i->question_id);

        $data = $favorites->map(fn (Favorite $f) => [
            'question_id' => $f->question_id,
            'context' => $f->context,
            'collection_ids' => ($items->get($f->context.'|'.$f->question_id) ?? collect())->pluck('favorite_collection_id')->values(),
            'created_at' => Present::time($f->created_at),
            'question' => ($revision = $revisions->get($f->question_id)) ? [
                'official_number' => $revision->source_question_id,
                'text' => $revision->question_text,
                'question_type' => $revision->question_type ?: 'single_choice',
                'topic' => $revision->smartmodus_kategorie ?: $revision->topic,
                'learning_state' => $progress->get($f->question_id)?->learning_state ?? 'neu',
                'last_seen_at' => Present::time($progress->get($f->question_id)?->last_seen_at),
            ] : null,
        ]);
        if ($validated['collection_id'] ?? null) {
            $data = $data->filter(fn ($f) => $f['collection_ids']->contains($validated['collection_id']));
        }

        return response()->json(['data' => $data->values()]);
    }

    /** Zuletzt falsch beantwortete Fragen für den freigeschalteten Kurs. */
    public function mistakes(Request $request, string $courseId): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId, 'favorites');
        $courseQuestionIds = $course->contentQuestionIds();
        if ($courseQuestionIds->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $progress = Progress::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->whereIn('question_id', $courseQuestionIds)
            ->where('attempt_count', '>', 0)->where('current_streak', 0)
            ->orderByDesc('last_seen_at')
            ->get();
        $questionIds = $progress->pluck('question_id')->all();
        if ($questionIds === []) {
            return response()->json(['data' => []]);
        }

        $questions = ContentQuestion::query()->currentlyValid()
            ->whereIn('id', $questionIds)
            ->with(['revisions' => fn ($query) => $query->where('editorial_status', 'published')->orderByDesc('revision_no')])
            ->get()->keyBy('id');
        $favoriteRows = Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('context', Favorite::CONTEXT_SMART_LEARNING)->whereIn('question_id', $questionIds)
            ->get(['question_id', 'created_at'])->keyBy('question_id');
        $favoriteIds = $favoriteRows->keys()->flip();
        $collections = FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('course_id', $course->id)->pluck('id');
        $collectionIds = FavoriteCollectionItem::where('tenant_id', $tenant->id)
            ->whereIn('favorite_collection_id', $collections)->where('context', Favorite::CONTEXT_SMART_LEARNING)
            ->whereIn('question_id', $questionIds)->orderBy('created_at')
            ->get(['question_id', 'favorite_collection_id'])->groupBy('question_id')
            ->map(fn ($items) => $items->pluck('favorite_collection_id')->map(fn ($id) => (string) $id)->values());

        $moduleByQuestion = [];
        foreach ($course->modules()->with('questions')->get() as $module) {
            foreach ($module->questions as $question) {
                $moduleByQuestion[$question->id] ??= $module->name;
            }
        }

        $items = [];
        foreach ($progress as $state) {
            $question = $questions->get($state->question_id);
            $revision = $question?->revisions->first();
            if (! $question || ! $revision) {
                continue;
            }
            $ids = $collectionIds->get($question->id, collect());
            $savedAt = $favoriteRows->get($question->id)?->created_at;

            $items[] = [
                'question_id' => (string) $question->id,
                'context' => Favorite::CONTEXT_SMART_LEARNING,
                'favorite' => $favoriteIds->has($question->id),
                'wrong' => true,
                'collection_id' => $ids->first(),
                'collection_ids' => $ids,
                'official_number' => $question->official_number !== null ? (string) $question->official_number : null,
                'text' => trim(strip_tags((string) $revision->question_text)),
                'module' => (string) ($moduleByQuestion[$question->id] ?? ''),
                'topic' => ($revision->smartmodus_kategorie ?: $revision->topic) ?: null,
                'saved_at' => $savedAt ? Present::time($savedAt) : null,
                'created_at' => $savedAt ? Present::time($savedAt) : null,
            ];
        }

        return response()->json(['data' => $items]);
    }

    /** Favorit setzen (optional mit Sammlungen); wiederholbar. */
    public function put(Request $request, string $courseId, string $questionId): JsonResponse
    {
        $validated = $request->validate([
            'context' => ['nullable', 'in:'.Favorite::CONTEXT_SMART_LEARNING.','.Favorite::CONTEXT_EXAM],
            'collection_ids' => ['nullable', 'array', 'max:50'],
            'collection_ids.*' => ['uuid'],
        ]);
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId, 'favorites');
        if (! $course->contentQuestionIds()->contains($questionId)) {
            throw ApiProblem::notFound('Frage');
        }
        $context = $validated['context'] ?? Favorite::CONTEXT_SMART_LEARNING;
        // Review-Runde 13: eine leere oder fremde Liste hätte den Favoriten
        // stillschweigend entfernt (Entfernen geht über DELETE).
        if (array_key_exists('collection_ids', $validated) && $validated['collection_ids'] !== null) {
            $own = FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('course_id', $course->id)
                ->whereIn('id', $validated['collection_ids'])->count();
            if ($validated['collection_ids'] === [] || $own !== count(array_unique($validated['collection_ids']))) {
                throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['collection_ids' => ['Bitte mindestens eine eigene Sammlung dieses Kurses angeben.']]);
            }
        }

        $favorite = Favorite::firstOrCreate(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'question_id' => $questionId, 'context' => $context]);
        if (($validated['collection_ids'] ?? null) !== null) {
            $this->collections->syncQuestion($tenant, $user, $course, $questionId, $context, $validated['collection_ids']);
        } else {
            $this->collections->ensureInDefault($tenant, $user, $course, $questionId, $context);
        }

        $collectionIds = FavoriteCollectionItem::where('tenant_id', $tenant->id)->where('question_id', $questionId)->where('context', $context)
            ->whereIn('favorite_collection_id', FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('course_id', $course->id)->select('id'))
            ->pluck('favorite_collection_id')->values();

        return response()->json(['data' => [
            'question_id' => $questionId,
            'context' => $context,
            'collection_ids' => $collectionIds,
            'created_at' => Present::time($favorite->created_at ?? now()),
        ]]);
    }

    public function destroy(Request $request, string $courseId, string $questionId): Response
    {
        $validated = $request->validate(['context' => ['nullable', 'in:'.Favorite::CONTEXT_SMART_LEARNING.','.Favorite::CONTEXT_EXAM]]);
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $this->access->require($tenant, $user, $courseId, 'favorites');
        $context = $validated['context'] ?? Favorite::CONTEXT_SMART_LEARNING;

        Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('question_id', $questionId)->where('context', $context)->delete();
        $this->collections->detachFromAllCollections($tenant, $user, $questionId, $context);

        return response()->noContent();
    }

    public function collections(Request $request, string $courseId): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId, 'favorites');
        $this->collections->assignUnsortedToDefault($tenant, $user, $course, Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->whereIn('question_id', $course->contentQuestionIds())->get());

        $collections = FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('course_id', $course->id)
            ->withCount('items')->orderByDesc('is_default')->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['data' => $collections->map(fn ($c) => $this->collection($c))->values()]);
    }

    public function storeCollection(Request $request, string $courseId): JsonResponse
    {
        $validated = $request->validate(['name' => ['bail', 'required', 'string', 'max:60']]);
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId, 'favorites');

        try {
            $collection = $this->collections->create($tenant, $user, $course, $validated['name']);
        } catch (UniqueConstraintViolationException) {
            throw $this->nameTaken();
        } catch (RuntimeException $e) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['name' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $this->collection($collection->loadCount('items'))], 201);
    }

    public function updateCollection(Request $request, string $courseId, string $collectionId): JsonResponse
    {
        $validated = $request->validate(['name' => ['bail', 'required', 'string', 'max:60']]);
        $collection = $this->ownCollection($request, $courseId, $collectionId);

        try {
            $this->collections->rename($collection, $validated['name']);
        } catch (UniqueConstraintViolationException) {
            throw $this->nameTaken();
        } catch (RuntimeException $e) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['name' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $this->collection($collection->fresh()->loadCount('items'))]);
    }

    public function destroyCollection(Request $request, string $courseId, string $collectionId): Response
    {
        $collection = $this->ownCollection($request, $courseId, $collectionId);
        try {
            $this->collections->delete($collection);
        } catch (RuntimeException $e) {
            throw new ApiProblem(409, 'COLLECTION_NOT_DELETABLE', 'Sammlung kann nicht gelöscht werden', $e->getMessage());
        }

        return response()->noContent();
    }

    private function ownCollection(Request $request, string $courseId, string $collectionId): FavoriteCollection
    {
        $tenant = $this->tenantContext->tenant();
        $course = $this->access->require($tenant, $request->user(), $courseId, 'favorites');
        $collection = FavoriteCollection::where('id', $collectionId)->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id)->where('course_id', $course->id)->first();
        if (! $collection) {
            throw ApiProblem::notFound('Sammlung');
        }

        return $collection;
    }

    private function nameTaken(): ApiProblem
    {
        return new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['name' => ['Eine Sammlung mit diesem Namen existiert bereits.']]);
    }

    private function collection(FavoriteCollection $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'is_default' => (bool) $c->is_default,
            'favorite_count' => (int) ($c->items_count ?? 0),
            'created_at' => Present::time($c->created_at),
            'updated_at' => Present::time($c->updated_at),
        ];
    }
}
