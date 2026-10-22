<?php

namespace App\Http\Controllers;

use App\Models\Attempt;
use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamSession;
use App\Models\Favorite;
use App\Models\FavoriteCollection;
use App\Models\FavoriteCollectionItem;
use App\Services\EntitlementService;
use App\Services\FavoriteCollectionService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Favoriten sind sowohl kurs- als auch bereichsgebunden: Smart-Learning und
 * Prüfungsfragen haben pro Kurs jeweils ihre eigene Favoriten-/Fehler-
 * Auswertung, damit ein im Smart-Learning gespeicherter Favorit bzw. Fehler
 * nicht mit einem während der Prüfungssimulation entstandenen vermischt
 * wird -- beide Bereiche leben aber als Tabs auf einer gemeinsamen Seite
 * (learning/favorites.blade.php), daher berechnen smartLearning() und
 * exam() hier beide Datensätze und unterscheiden sich nur im aktiven Tab.
 */
class FavoriteController extends Controller
{
    public function smartLearning(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, FavoriteCollectionService $collections, CourseDefinition $course): View
    {
        return $this->renderFavorites($request, $tenantContext, $entitlements, $collections, $course, 'smart');
    }

    public function exam(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, FavoriteCollectionService $collections, CourseDefinition $course): View
    {
        return $this->renderFavorites($request, $tenantContext, $entitlements, $collections, $course, 'exam');
    }

    private function renderFavorites(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, FavoriteCollectionService $collectionService, CourseDefinition $course, string $activeTab): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403, 'Für diesen Kurs liegt kein aktives Entitlement vor.');

        $course->loadMissing('modules.questions');
        $questionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique();

        $smartFavorites = Favorite::with('question.revisions')
            ->where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('context', Favorite::CONTEXT_SMART_LEARNING)
            ->whereIn('question_id', $questionIds)
            ->latest('created_at')->get();

        $smartWrongQuestionIds = Attempt::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('correct', false)
            ->whereIn('question_id', $questionIds)
            ->orderByDesc('created_at')
            ->pluck('question_id')->unique()->take(20);

        $smartWrongQuestions = ContentQuestion::with('revisions')->whereIn('id', $smartWrongQuestionIds)->get();

        $examFavorites = Favorite::with('question.revisions')
            ->where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('context', Favorite::CONTEXT_EXAM)
            ->whereIn('question_id', $questionIds)
            ->latest('created_at')->get();

        // "Meine Fehler" für Prüfungsfragen stammt aus den tatsächlichen
        // Prüfungssimulationen dieses Kurses (exam_session_question), nicht
        // aus den Smart-Learning-Attempts.
        $examWrongQuestionIds = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->with('questions')
            ->get()
            ->flatMap->questions
            // Bug-Review B2: Collection::where() vergleicht standardmäßig
            // lose -- correct ist bei noch unbeantworteten Fragen NULL, und
            // null == false ist in PHP wahr. Unbeantwortete Fragen zählten
            // dadurch fälschlich als "Fehler". whereStrict erzwingt ===.
            ->whereStrict('correct', false)
            ->sortByDesc('answered_at')
            ->pluck('question_id')->unique()->take(20);

        $examWrongQuestions = ContentQuestion::with('revisions')->whereIn('id', $examWrongQuestionIds)->get();

        // Default-Collection existiert ab dem ersten Aufruf der Seite, auch
        // wenn der Lerner noch nie etwas bewusst einsortiert hat.
        $collectionService->defaultFor($tenant, $user, $course);

        $collections = FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('course_id', $course->id)
            ->orderByDesc('is_default')->orderBy('sort_order')->orderBy('name')->get();

        $itemsByContext = FavoriteCollectionItem::where('tenant_id', $tenant->id)
            ->whereIn('favorite_collection_id', $collections->pluck('id'))
            ->whereIn('question_id', $questionIds)
            ->get()
            ->groupBy('context')
            ->map(fn ($rows) => $rows->groupBy('question_id')->map(fn ($g) => $g->pluck('favorite_collection_id')->all()));

        return view('learning.favorites', [
            'course' => $course,
            'activeTab' => $activeTab,
            'smartFavorites' => $smartFavorites,
            'smartWrongQuestions' => $smartWrongQuestions,
            'examFavorites' => $examFavorites,
            'examWrongQuestions' => $examWrongQuestions,
            // Rückwärtskompatible Aliase auf den jeweils angefragten Bereich.
            'favorites' => $activeTab === 'exam' ? $examFavorites : $smartFavorites,
            'wrongQuestions' => $activeTab === 'exam' ? $examWrongQuestions : $smartWrongQuestions,
            'collections' => $collections,
            'smartItemCollections' => $itemsByContext->get(Favorite::CONTEXT_SMART_LEARNING, collect()),
            'examItemCollections' => $itemsByContext->get(Favorite::CONTEXT_EXAM, collect()),
        ]);
    }

    public function store(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, ContentQuestion $question, FavoriteCollectionService $collections): Response
    {
        $tenant = $tenantContext->tenant();
        $context = $this->resolveContext($request);

        // Sicherheitsreview N8: ohne diese Prüfung ließ sich jede beliebige,
        // globale Frage favorisieren, unabhängig von Kurs oder Entitlement
        // (kein Datenabfluss, da die Anzeige ohnehin nach Kurs filtert, aber
        // unnötige/fremde Favoriten-Zeilen). course_id ist deshalb jetzt
        // Pflicht; alle aktuellen Aufrufer (Lernmodus, Prüfung,
        // Favoriten-Karte) schicken es bereits mit.
        $validated = $request->validate([
            'course_id' => ['required', 'uuid'],
        ]);

        // Bewusst der tenant-gescopte Default-Query (nicht
        // withoutGlobalScopes()) -- verhindert, dass eine manipulierte
        // course_id eine fremde, nicht-globale Tenant-Kurs-ID trifft.
        $course = CourseDefinition::find($validated['course_id']);
        abort_unless($course, 404);
        abort_unless($entitlements->hasAccess($tenant, $request->user(), $course), 403, 'Für diesen Kurs liegt kein aktives Entitlement vor.');

        $course->loadMissing('modules.questions');
        $courseQuestionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id');
        abort_unless($courseQuestionIds->contains($question->id), 403, 'Frage gehört nicht zum berechtigten Kurs.');

        Favorite::firstOrCreate([
            'tenant_id' => $tenant->id,
            'user_id' => $request->user()->id,
            'question_id' => $question->id,
            'context' => $context,
        ]);

        $collections->ensureInDefault($tenant, $request->user(), $course, $question->id, $context);

        return back();
    }

    public function destroy(Request $request, TenantContext $tenantContext, ContentQuestion $question, FavoriteCollectionService $collections): Response
    {
        $tenant = $tenantContext->tenant();
        $context = $this->resolveContext($request);

        Favorite::where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id)
            ->where('question_id', $question->id)
            ->where('context', $context)
            ->delete();

        $collections->detachFromAllCollections($tenant, $question->id, $context);

        return back();
    }

    private function resolveContext(Request $request): string
    {
        return $request->input('context') === Favorite::CONTEXT_EXAM
            ? Favorite::CONTEXT_EXAM
            : Favorite::CONTEXT_SMART_LEARNING;
    }
}
