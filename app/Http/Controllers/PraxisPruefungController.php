<?php

namespace App\Http\Controllers;

use App\Models\CourseDefinition;
use App\Services\CourseProgressService;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PraxisPruefungController extends Controller
{
    public function index(
        Request $request,
        TenantContext $tenantContext,
        EntitlementService $entitlements,
        CourseProgressService $courseProgress,
        CourseDefinition $course,
    ): View {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403, 'Für diesen Kurs liegt kein aktives Entitlement vor.');

        $threshold = $tenant->branding->exam_readiness_threshold_percent;
        $percent = $courseProgress->overallPercent($course, $tenant, $user);

        abort_unless($percent >= $threshold, 403, "Für die Buchung von Prüfung & Praxis werden mindestens {$threshold}% Kursfortschritt benötigt.");

        return view('praxis-pruefung.index', ['course' => $course]);
    }
}
