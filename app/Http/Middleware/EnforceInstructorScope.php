<?php

namespace App\Http\Middleware;

use App\Models\PraxisFormSubmission;
use App\Models\User;
use App\Models\WillkommensboxOrder;
use App\Support\InstructorScope;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ausbilder mit zugeordneten Kursen (InstructorScope) öffnen im
 * Admin-Bereich nur Teilnehmer, Praxis-Anmeldungen und Willkommensboxen
 * aus ihren Kursen; alles andere antwortet wie nicht vorhanden (404).
 * Die Listen filtern die Controller selbst.
 */
class EnforceInstructorScope
{
    public function __construct(private TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $this->tenantContext->has()) {
            return $next($request);
        }
        $tenant = $this->tenantContext->tenant();
        $courseIds = InstructorScope::courseIds($tenant, $user);
        if ($courseIds === null) {
            return $next($request);
        }

        $learner = $request->route('user');
        if ($learner instanceof User) {
            abort_unless(InstructorScope::canSeeLearner($tenant, $user, $learner->id), 404);
        }
        $submission = $request->route('submission');
        if ($submission instanceof PraxisFormSubmission) {
            abort_unless($courseIds->contains($submission->course_id), 404);
        }
        $order = $request->route('willkommensboxOrder');
        if ($order instanceof WillkommensboxOrder) {
            abort_unless(InstructorScope::canSeeLearner($tenant, $user, $order->user_id), 404);
        }

        return $next($request);
    }
}
