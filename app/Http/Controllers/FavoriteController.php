<?php

namespace App\Http\Controllers;

use App\Models\Attempt;
use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\Favorite;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class FavoriteController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403, 'Für diesen Kurs liegt kein aktives Entitlement vor.');

        $course->loadMissing('modules.questions');
        $questionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique();

        $favorites = Favorite::with('question.revisions')
            ->where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->whereIn('question_id', $questionIds)
            ->latest('created_at')->get();

        $wrongQuestionIds = Attempt::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('correct', false)
            ->whereIn('question_id', $questionIds)
            ->orderByDesc('created_at')
            ->pluck('question_id')->unique()->take(20);

        $wrongQuestions = ContentQuestion::with('revisions')->whereIn('id', $wrongQuestionIds)->get();

        return view('learning.favorites', ['course' => $course, 'favorites' => $favorites, 'wrongQuestions' => $wrongQuestions]);
    }

    public function store(Request $request, TenantContext $tenantContext, ContentQuestion $question): Response
    {
        Favorite::firstOrCreate([
            'tenant_id' => $tenantContext->id(),
            'user_id' => $request->user()->id,
            'question_id' => $question->id,
        ]);

        return back();
    }

    public function destroy(Request $request, TenantContext $tenantContext, ContentQuestion $question): Response
    {
        Favorite::where('tenant_id', $tenantContext->id())
            ->where('user_id', $request->user()->id)
            ->where('question_id', $question->id)
            ->delete();

        return back();
    }
}
