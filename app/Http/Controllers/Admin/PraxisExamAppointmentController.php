<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PraxisFormSubmission;
use App\Models\TenantCourseLocation;
use App\Services\PraxisExamAppointmentService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
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

        $locationIds = TenantCourseLocation::where('tenant_id', $tenantContext->id())
            ->whereJsonContains('course_ids', $submission->course_id)
            ->pluck('id');

        // Das "Ort"-Feld liefert entweder eine echte Orts-UUID, den
        // Spezialwert "online" (Onlinekurs, siehe PraxisExamAppointment::
        // isOnline) oder bleibt leer -- kein eigenes tenant_course_location-
        // Fake-Datensatz dafür nötig.
        $validated = $request->validate([
            'exam_date' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:exam_date'],
            'slot' => ['nullable', 'integer', 'min:0'],
            'label' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', Rule::in([...$locationIds->all(), 'online'])],
            // Sicherheitsreview N1: auf http/https eingeschränkt, sonst
            // ließe die bloße "url"-Regel auch javascript:/data:-Schemata durch.
            'online_meeting_url' => ['nullable', 'url:http,https', 'max:500'],
        ]);

        $slot = $request->filled('slot') ? (int) $request->input('slot') : null;
        $isOnline = ($validated['location'] ?? null) === 'online';
        $locationId = $isOnline ? null : ($validated['location'] ?? null);

        try {
            $appointment = $appointments->propose(
                $submission,
                $request->user(),
                Carbon::parse($validated['exam_date']),
                isset($validated['ends_at']) ? Carbon::parse($validated['ends_at']) : null,
                $slot,
                $validated['label'] ?? null,
                $locationId,
                $isOnline,
                $validated['online_meeting_url'] ?? null,
                $validated['description'] ?? null,
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
