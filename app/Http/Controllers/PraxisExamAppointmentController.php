<?php

namespace App\Http\Controllers;

use App\Models\CourseDefinition;
use App\Models\PraxisExamAppointment;
use App\Services\PraxisExamAppointmentService;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rückmeldung des Lerners auf einen von der Bootsschule vorgeschlagenen
 * Termin -- Prüfungstermin (slot 0) oder einer von beliebig vielen
 * zusätzlichen, frei benannten Terminen (slot 1, 2, ..., siehe
 * PraxisExamAppointmentService). Gegenstück zum Vorschlag im
 * Bootsschul-Admin (Admin\PraxisExamAppointmentController).
 */
class PraxisExamAppointmentController extends Controller
{
    public function respond(Request $request, CourseDefinition $course, PraxisExamAppointment $examAppointment, PraxisExamAppointmentService $appointments): Response
    {
        $user = $request->user();

        // Die BelongsToTenant-Scope auf PraxisExamAppointment filtert zwar
        // schon nach Tenant, aber nicht nach Nutzer -- ohne diese Prüfung
        // könnte ein Lerner auf den Terminvorschlag eines anderen Lerners
        // derselben Bootsschule antworten.
        abort_unless(
            $examAppointment->submission->user_id === $user->id && $examAppointment->submission->course_id === $course->id,
            404
        );

        $validated = $request->validate([
            'decision' => ['required', 'in:accepted,declined'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $appointments->respond($examAppointment, $validated['decision'], $validated['comment'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        $status = $validated['decision'] === 'accepted'
            ? '"'.$examAppointment->label.'" bestätigt.'
            : 'Rückmeldung gespeichert -- deine Bootsschule schlägt dir einen neuen Termin vor.';

        return redirect()->route('praxis-pruefung.index', $course)->with('status', $status);
    }
}
