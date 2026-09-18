<?php

namespace App\Http\Controllers;

use App\Models\Attempt;
use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamSession;
use App\Models\Favorite;
use App\Services\EntitlementService;
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
    public function smartLearning(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): View
    {
        return $this->renderFavorites($request, $tenantContext, $entitlements, $course, 'smart');
    }

    public function exam(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): View
    {
        return $this->renderFavorites($request, $tenantContext, $entitlements, $course, 'exam');
    }

    private function renderFavorites(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course, string $activeTab): View
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
            ->where('correct', false)
            ->sortByDesc('answered_at')
            ->pluck('question_id')->unique()->take(20);

        $examWrongQuestions = ContentQuestion::with('revisions')->whereIn('id', $examWrongQuestionIds)->get();

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
        ]);
    }

    public function store(Request $request, TenantContext $tenantContext, ContentQuestion $question): Response
    {
        Favorite::firstOrCreate([
            'tenant_id' => $tenantContext->id(),
            'user_id' => $request->user()->id,
            'question_id' => $question->id,
            'context' => $this->resolveContext($request),
        ]);

        return back();
    }

    public function destroy(Request $request, TenantContext $tenantContext, ContentQuestion $question): Response
    {
        Favorite::where('tenant_id', $tenantContext->id())
            ->where('user_id', $request->user()->id)
            ->where('question_id', $question->id)
            ->where('context', $this->resolveContext($request))
            ->delete();

        return back();
    }

    private function resolveContext(Request $request): string
    {
        return $request->input('context') === Favorite::CONTEXT_EXAM
            ? Favorite::CONTEXT_EXAM
            : Favorite::CONTEXT_SMART_LEARNING;
    }
}
