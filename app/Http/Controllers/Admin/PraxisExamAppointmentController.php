<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PraxisFormSubmission;
use App\Services\PraxisExamAppointmentService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prüfungstermin-Vorschlag der Bootsschule zu einer Praxis-Anmeldung
 * (siehe PraxisExamAppointmentService) -- Gegenstück zur Rückmeldung des
 * Lerners (PraxisExamAppointmentController im Lerner-Bereich).
 */
class PraxisExamAppointmentController extends Controller
{
    public function store(Request $request, TenantContext $tenantContext, PraxisFormSubmission $submission, PraxisExamAppointmentService $appointments): Response
    {
        abort_unless($submission->tenant_id === $tenantContext->id(), 404);

        $validated = $request->validate([
            'exam_date' => ['required', 'date'],
        ]);

        try {
            $appointment = $appointments->propose($submission, $request->user(), Carbon::parse($validated['exam_date']));
        } catch (RuntimeException $e) {
            return back()->withErrors(['exam_date' => $e->getMessage()]);
        }

        AuditLog::record(
            $tenantContext->id(), $request->user()->id, 'praxis-exam-appointment.propose',
            'praxis_exam_appointment', $appointment->id, null, $appointment->toArray()
        );

        return back()->with('status', 'Prüfungstermin vorgeschlagen (Runde '.$appointment->round.').');
    }
}
