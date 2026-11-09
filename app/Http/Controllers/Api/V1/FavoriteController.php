<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Api\CourseAccess;
use App\Api\Present;
use App\Http\Controllers\Controller;
use App\Models\ContentQuestion;
use App\Models\Favorite;
use App\Models\FavoriteCollection;
use App\Models\FavoriteCollectionItem;
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
        $course = $this->access->require($tenant, $user, $courseId);
        $this->collections->defaultFor($tenant, $user, $course);

        $favorites = Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->whereIn('question_id', $course->contentQuestionIds())
            ->whereIn('question_id', ContentQuestion::query()->currentlyValid()->select('id'))
            ->when($validated['context'] ?? null, fn ($q, $context) => $q->where('context', $context))
            ->latest('created_at')->get();
        $items = FavoriteCollectionItem::where('tenant_id', $tenant->id)
            ->whereIn('favorite_collection_id', FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('course_id', $course->id)->select('id'))
            ->get()->groupBy(fn ($i) => $i->context.'|'.$i->question_id);

        $data = $favorites->map(fn (Favorite $f) => [
            'question_id' => $f->question_id,
            'context' => $f->context,
            'collection_ids' => ($items->get($f->context.'|'.$f->question_id) ?? collect())->pluck('favorite_collection_id')->values(),
            'created_at' => Present::time($f->created_at),
        ]);
        if ($validated['collection_id'] ?? null) {
            $data = $data->filter(fn ($f) => $f['collection_ids']->contains($validated['collection_id']));
        }

        return response()->json(['data' => $data->values()]);
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
        $course = $this->access->require($tenant, $user, $courseId);
        if (! $course->contentQuestionIds()->contains($questionId)) {
            throw ApiProblem::notFound('Frage');
        }
        $context = $validated['context'] ?? Favorite::CONTEXT_SMART_LEARNING;

        $favorite = Favorite::firstOrCreate(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'question_id' => $questionId, 'context' => $context]);
        if (array_key_exists('collection_ids', $validated)) {
            $this->collections->syncQuestion($tenant, $user, $course, $questionId, $context, $validated['collection_ids'] ?? []);
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
        $this->access->require($tenant, $user, $courseId);
        $context = $validated['context'] ?? Favorite::CONTEXT_SMART_LEARNING;

        Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('question_id', $questionId)->where('context', $context)->delete();
        $this->collections->detachFromAllCollections($tenant, $user, $questionId, $context);

        return response()->noContent();
    }

    public function collections(Request $request, string $courseId): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId);
        $this->collections->defaultFor($tenant, $user, $course);

        $collections = FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('course_id', $course->id)
            ->withCount('items')->orderByDesc('is_default')->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['data' => $collections->map(fn ($c) => $this->collection($c))->values()]);
    }

    public function storeCollection(Request $request, string $courseId): JsonResponse
    {
        $validated = $request->validate(['name' => ['bail', 'required', 'string', 'max:60']]);
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId);

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
        $course = $this->access->require($tenant, $request->user(), $courseId);
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
