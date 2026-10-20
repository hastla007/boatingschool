<?php

namespace App\Http\Controllers;

use App\Models\CourseDefinition;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
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

        $percent = $courseProgress->overallPercent($course, $tenant, $user);

        $template = PraxisFormTemplate::where('active', true)
            ->whereJsonContains('course_ids', $course->id)
            ->first();

        if (! $template) {
            // Noch kein Praxisformular zugewiesen: altes Verhalten
            // beibehalten (Platzhalterseite, gated über die
            // bootsschulweite Branding-Schwelle statt eines Formulars).
            $threshold = $tenant->branding->exam_readiness_threshold_percent;
            abort_unless($percent >= $threshold, 403, "Für die Buchung von Prüfung & Praxis werden mindestens {$threshold}% Kursfortschritt benötigt.");

            return view('praxis-pruefung.index', ['course' => $course, 'state' => 'unavailable']);
        }

        if ($percent < $template->min_progress_percent) {
            return view('praxis-pruefung.index', [
                'course' => $course,
                'state' => 'locked',
                'percent' => $percent,
                'threshold' => $template->min_progress_percent,
            ]);
        }

        $submission = PraxisFormSubmission::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        if ($submission) {
            return view('praxis-pruefung.index', [
                'course' => $course,
                'state' => 'submitted',
                'template' => $submission->template ?? $template,
                'submission' => $submission,
            ]);
        }

        return view('praxis-pruefung.index', [
            'course' => $course,
            'state' => 'form',
            'template' => $template,
        ]);
    }
}
