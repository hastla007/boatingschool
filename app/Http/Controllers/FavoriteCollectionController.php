<?php

namespace App\Http\Controllers;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\Favorite;
use App\Models\FavoriteCollection;
use App\Services\FavoriteCollectionService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anlegen/Umbenennen/Löschen von Favoriten-Collections sowie die
 * Zuordnung einzelner Fragen zu ihnen -- siehe FavoriteCollectionService
 * für die eigentliche Geschäftslogik. Gegenstück im Lerner-Bereich zu
 * FavoriteController, der nur den einfachen Stern (favorisiert ja/nein)
 * verwaltet.
 */
class FavoriteCollectionController extends Controller
{
    public function store(Request $request, TenantContext $tenantContext, CourseDefinition $course, FavoriteCollectionService $collections): Response
    {
        $tenant = $tenantContext->tenant();
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);

        try {
            $collections->create($tenant, $request->user(), $course, $validated['name']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        return back()->with('status', 'Collection angelegt.');
    }

    public function update(Request $request, TenantContext $tenantContext, FavoriteCollection $favoriteCollection, FavoriteCollectionService $collections): Response
    {
        abort_unless($favoriteCollection->tenant_id === $tenantContext->id() && $favoriteCollection->user_id === $request->user()->id, 404);

        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);

        try {
            $collections->rename($favoriteCollection, $validated['name']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        return back()->with('status', 'Collection umbenannt.');
    }

    public function destroy(Request $request, TenantContext $tenantContext, FavoriteCollection $favoriteCollection, FavoriteCollectionService $collections): Response
    {
        abort_unless($favoriteCollection->tenant_id === $tenantContext->id() && $favoriteCollection->user_id === $request->user()->id, 404);

        try {
            $collections->delete($favoriteCollection);
        } catch (RuntimeException $e) {
            return back()->withErrors(['collection' => $e->getMessage()]);
        }

        return back()->with('status', 'Collection gelöscht.');
    }

    public function sync(Request $request, TenantContext $tenantContext, CourseDefinition $course, ContentQuestion $question, FavoriteCollectionService $collections): Response
    {
        $tenant = $tenantContext->tenant();

        $validated = $request->validate([
            'context' => ['required', 'in:'.Favorite::CONTEXT_SMART_LEARNING.','.Favorite::CONTEXT_EXAM],
            'collection_ids' => ['nullable', 'array'],
            'collection_ids.*' => ['uuid'],
        ]);

        $collections->syncQuestion($tenant, $request->user(), $course, $question->id, $validated['context'], $validated['collection_ids'] ?? []);

        return back()->with('status', 'Collections aktualisiert.');
    }
}
