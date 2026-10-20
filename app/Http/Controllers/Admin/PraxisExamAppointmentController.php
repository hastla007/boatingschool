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
 * Terminvorschlag der Bootsschule zu einer Praxis-Anmeldung -- für den
 * Prüfungstermin selbst (slot 0, ohne "slot"-Feld im Formular), eine neue
 * Runde für einen bereits bestehenden zusätzlichen Termin (slot gesetzt),
 * oder einen komplett neuen, frei benannten Termin (slot leer, "label"
 * Pflicht -- siehe PraxisExamAppointmentService). Gegenstück zur
 * Rückmeldung des Lerners (PraxisExamAppointmentController im
 * Lerner-Bereich).
 */
class PraxisExamAppointmentController extends Controller
{
    public function store(Request $request, TenantContext $tenantContext, PraxisFormSubmission $submission, PraxisExamAppointmentService $appointments): Response
    {
        abort_unless($submission->tenant_id === $tenantContext->id(), 404);

        $validated = $request->validate([
            'exam_date' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:exam_date'],
            'slot' => ['nullable', 'integer', 'min:0'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        $slot = $request->filled('slot') ? (int) $request->input('slot') : null;

        try {
            $appointment = $appointments->propose(
                $submission,
                $request->user(),
                Carbon::parse($validated['exam_date']),
                isset($validated['ends_at']) ? Carbon::parse($validated['ends_at']) : null,
                $slot,
                $validated['label'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['exam_date' => $e->getMessage()]);
        }

        AuditLog::record(
            $tenantContext->id(), $request->user()->id, 'praxis-exam-appointment.propose',
            'praxis_exam_appointment', $appointment->id, null, $appointment->toArray()
        );

        return back()->with('status', '"'.$appointment->label.'" vorgeschlagen (Runde '.$appointment->round.').');
    }
}
