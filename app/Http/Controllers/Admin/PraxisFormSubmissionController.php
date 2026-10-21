<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PraxisFormSubmission;
use App\Models\TenantCourseLocation;
use App\Support\TenantContext;
use Illuminate\View\View;

/**
 * Eigenständige Detailseite zu einer einzelnen Praxis-Anmeldung --
 * verlinkt aus der Tabelle im "Praxis-Anmeldungen"-Tab (siehe
 * AdminDashboardController), zeigt dieselben Inhalte wie der Abschnitt auf
 * der Teilnehmer-Detailseite (siehe admin.partials._praxis-submission),
 * nur fokussiert auf genau eine Anmeldung statt alle eines Teilnehmers.
 */
class PraxisFormSubmissionController extends Controller
{
    public function show(TenantContext $tenantContext, PraxisFormSubmission $submission): View
    {
        abort_unless($submission->tenant_id === $tenantContext->id(), 404);

        $submission->load(['user', 'course', 'template', 'examAppointments.location']);

        $submissionLocations = TenantCourseLocation::where('tenant_id', $tenantContext->id())
            ->whereJsonContains('course_ids', $submission->course_id)
            ->orderBy('sort_order')
            ->get();

        return view('admin.praxis-form-submissions.show', [
            'submission' => $submission,
            'submissionLocations' => $submissionLocations,
        ]);
    }
}
