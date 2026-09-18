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

class FavoriteController extends Controller
{
    public function smartLearning(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403, 'Für diesen Kurs liegt kein aktives Entitlement vor.');

        $course->loadMissing('modules.questions');
        $questionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique();

        $favorites = Favorite::with('question.revisions')
            ->where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('context', Favorite::CONTEXT_SMART_LEARNING)
            ->whereIn('question_id', $questionIds)
            ->latest('created_at')->get();

        $wrongQuestionIds = Attempt::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('correct', false)
            ->whereIn('question_id', $questionIds)
            ->orderByDesc('created_at')
            ->pluck('question_id')->unique()->take(20);

        $wrongQuestions = ContentQuestion::with('revisions')->whereIn('id', $wrongQuestionIds)->get();

        return view('learning.favorites', [
            'course' => $course,
            'sectionLabel' => 'Smart-Learning',
            'favorites' => $favorites,
            'wrongQuestions' => $wrongQuestions,
        ]);
    }

    public function exam(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403, 'Für diesen Kurs liegt kein aktives Entitlement vor.');

        $course->loadMissing('modules.questions');
        $questionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique();

        $favorites = Favorite::with('question.revisions')
            ->where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('context', Favorite::CONTEXT_EXAM)
            ->whereIn('question_id', $questionIds)
            ->latest('created_at')->get();

        // "Meine Fehler" für Prüfungsfragen stammt aus den tatsächlichen
        // Prüfungssimulationen dieses Kurses (exam_session_question), nicht
        // aus den Smart-Learning-Attempts.
        $wrongQuestionIds = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->with('questions')
            ->get()
            ->flatMap->questions
            ->where('correct', false)
            ->sortByDesc('answered_at')
            ->pluck('question_id')->unique()->take(20);

        $wrongQuestions = ContentQuestion::with('revisions')->whereIn('id', $wrongQuestionIds)->get();

        return view('learning.favorites', [
            'course' => $course,
            'sectionLabel' => 'Prüfungsfragen',
            'favorites' => $favorites,
            'wrongQuestions' => $wrongQuestions,
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
