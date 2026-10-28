<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PraxisFormSubmission;
use App\Models\TenantCourseLocation;
use App\Services\PraxisFormDataResolver;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Eigenständige Detailseite zu einer einzelnen Praxis-Anmeldung --
 * verlinkt aus der Tabelle im "Praxis-Anmeldungen"-Tab (siehe
 * AdminDashboardController), zeigt dieselben Inhalte wie der Abschnitt auf
 * der Teilnehmer-Detailseite (siehe admin.partials._praxis-submission),
 * nur fokussiert auf genau eine Anmeldung statt alle eines Teilnehmers.
 *
 * Bug-Review F6: die Formulardaten waren bisher nach dem Absenden rein
 * lesend -- ein Tippfehler des Lerners (z. B. Telefonnummer) blieb der
 * Bootsschule ohne eigenen Datenbankzugriff dauerhaft erhalten, und eine
 * versehentliche oder nicht mehr benötigte Anmeldung ließ sich nicht
 * löschen (der Unique-Index tenant_id/user_id/course_id blockierte dann
 * auch jede neue Anmeldung desselben Lerners für denselben Kurs).
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

    public function edit(TenantContext $tenantContext, PraxisFormSubmission $submission): View
    {
        abort_unless($submission->tenant_id === $tenantContext->id(), 404);

        $submission->load(['user', 'course', 'template']);

        abort_unless($submission->template, 404, 'Die Formularvorlage dieser Anmeldung wurde gelöscht und kann nicht mehr bearbeitet werden.');

        $submissionLocations = TenantCourseLocation::where('tenant_id', $tenantContext->id())
            ->whereJsonContains('course_ids', $submission->course_id)
            ->orderBy('sort_order')
            ->get();

        return view('admin.praxis-form-submissions.edit', [
            'submission' => $submission,
            'submissionLocations' => $submissionLocations,
        ]);
    }

    public function update(
        Request $request,
        TenantContext $tenantContext,
        PraxisFormSubmission $submission,
        PraxisFormDataResolver $dataResolver,
    ): Response {
        abort_unless($submission->tenant_id === $tenantContext->id(), 404);

        $template = $submission->template;
        abort_unless($template, 404, 'Die Formularvorlage dieser Anmeldung wurde gelöscht und kann nicht mehr bearbeitet werden.');

        $locations = TenantCourseLocation::where('tenant_id', $tenantContext->id())
            ->whereJsonContains('course_ids', $submission->course_id)
            ->with('pruefungsausschuss')
            ->get();

        $validated = $request->validate($dataResolver->rulesFor($template, $locations));
        $data = $dataResolver->resolveLocationFields($template, $locations, $validated, $dataResolver->allCourses());

        $before = $submission->toArray();
        $submission->update(['data' => $data]);

        AuditLog::record(
            $tenantContext->id(), $request->user()->id, 'praxis-form-submission.update',
            'praxis_form_submission', $submission->id, $before, $submission->fresh()->toArray()
        );

        return redirect()->route('admin.praxis-form-submissions.show', $submission)->with('status', 'Anmeldung aktualisiert.');
    }

    public function destroy(Request $request, TenantContext $tenantContext, PraxisFormSubmission $submission): Response
    {
        abort_unless($submission->tenant_id === $tenantContext->id(), 404);

        $before = $submission->toArray();
        $submission->delete();

        AuditLog::record(
            $tenantContext->id(), $request->user()->id, 'praxis-form-submission.delete',
            'praxis_form_submission', $submission->id, $before, null
        );

        return redirect()->route('admin.dashboard', ['tab' => 'praxis-anmeldungen'])->with('status', 'Anmeldung gelöscht.');
    }
}
