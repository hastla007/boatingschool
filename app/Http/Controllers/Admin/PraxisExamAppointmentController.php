<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PraxisExamAppointment;
use App\Models\PraxisFormSubmission;
use App\Models\TenantCourseLocation;
use App\Notifications\PraxisExamAppointmentUpdated;
use App\Services\PraxisExamAppointmentService;
use App\Support\BestEffortMail;
use App\Support\ExamRegistration;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
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

        // Bug-Review (Umstellung auf Selbstanmeldung): steht der Kurs auf
        // "Teilnehmer meldet sich selbst an", bucht der Teilnehmer seinen
        // Prüfungstermin selbst -- ein neuer Terminvorschlag der Bootsschule
        // wäre widersprüchlich (und erschien bisher nur noch Teilnehmern,
        // die das Termintool überhaupt noch sehen). Bestehende Termine und
        // Antworten bleiben unberührt.
        if ($submission->course && ExamRegistration::isSelf($tenantContext->tenant(), $submission->course)) {
            return back()->withErrors(['exam_date' => 'Für diesen Kurs melden sich die Teilnehmer selbst zur Prüfung an (Einstellung im Tab „Kurse“). Neue Terminvorschläge sind deshalb nicht möglich.']);
        }

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
                // Bug-Review UTC: das datetime-local-Feld liefert einen
                // Offset-losen String, den der Admin in seiner eigenen
                // (deutschen) Ortszeit eingibt -- APP_TIMEZONE ist aber
                // bewusst UTC (siehe GermanTime, Bug-Review B30). Ohne die
                // explizite Interpretations-Zeitzone läse Carbon::parse()
                // den String fälschlich als UTC. setTimezone('UTC') ist
                // zusätzlich nötig: Eloquents 'datetime'-Cast serialisiert
                // beim Speichern offset-los in der AKTUELLEN Zeitzone des
                // Carbon-Objekts (hier sonst weiterhin Europe/Berlin), und
                // die UTC-Session der Postgres-Verbindung würde diesen
                // String dann als UTC fehlinterpretieren -- genau das vom
                // GermanTime-Kommentar beschriebene Problem, nur umgekehrt.
                Carbon::parse($validated['exam_date'], 'Europe/Berlin')->setTimezone('UTC'),
                isset($validated['ends_at']) ? Carbon::parse($validated['ends_at'], 'Europe/Berlin')->setTimezone('UTC') : null,
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

        // Bug-Review F5: im gesamten Ablauf von Praxis-Anmeldung, Terminen
        // und Willkommensbox wurde bisher keine Mail verschickt -- der
        // Lerner bemerkte einen neuen Terminvorschlag nur, wenn er von
        // selbst wieder auf die Praxis-Seite seines Kurses sah.
        BestEffortMail::send(
            fn () => Notification::send($appointment->submission->user, new PraxisExamAppointmentUpdated($appointment, 'proposed')),
            "Terminvorschlag {$appointment->id}"
        );

        return back()->with('status', '"'.$appointment->label.'" vorgeschlagen (Runde '.$appointment->round.').');
    }

    /**
     * Bug-Review F4: es gab bisher keine Möglichkeit, einen eigenen, noch
     * offenen Vorschlag zurückzuziehen (z. B. bei einem Tippfehler im
     * Datum).
     */
    public function withdraw(TenantContext $tenantContext, PraxisFormSubmission $submission, PraxisExamAppointment $examAppointment, PraxisExamAppointmentService $appointments): Response
    {
        abort_unless($submission->tenant_id === $tenantContext->id(), 404);
        abort_unless($examAppointment->praxis_form_submission_id === $submission->id, 404);

        $before = $examAppointment->toArray();

        try {
            $appointments->withdraw($examAppointment);
        } catch (RuntimeException $e) {
            return back()->withErrors(['exam_date' => $e->getMessage()]);
        }

        AuditLog::record(
            $tenantContext->id(), request()->user()->id, 'praxis-exam-appointment.withdraw',
            'praxis_exam_appointment', $examAppointment->id, $before, $examAppointment->fresh()->toArray()
        );

        // Bug-Review F5: siehe store() -- der Lerner bemerkte ein
        // zurückgezogenes Angebot bisher nicht von selbst.
        BestEffortMail::send(
            fn () => Notification::send($examAppointment->submission->user, new PraxisExamAppointmentUpdated($examAppointment, 'withdrawn')),
            "Terminrückzug {$examAppointment->id}"
        );

        return back()->with('status', 'Terminvorschlag zurückgezogen.');
    }

    /**
     * Bug-Review F4: ein bereits angenommener (fixierter) Termin ließ sich
     * nicht mehr absagen -- z. B. wenn der Prüfungsausschuss den Termin
     * doch nicht wahrnehmen kann.
     */
    public function cancel(TenantContext $tenantContext, PraxisFormSubmission $submission, PraxisExamAppointment $examAppointment, PraxisExamAppointmentService $appointments): Response
    {
        abort_unless($submission->tenant_id === $tenantContext->id(), 404);
        abort_unless($examAppointment->praxis_form_submission_id === $submission->id, 404);

        $before = $examAppointment->toArray();

        try {
            $appointments->cancel($examAppointment);
        } catch (RuntimeException $e) {
            return back()->withErrors(['exam_date' => $e->getMessage()]);
        }

        AuditLog::record(
            $tenantContext->id(), request()->user()->id, 'praxis-exam-appointment.cancel',
            'praxis_exam_appointment', $examAppointment->id, $before, $examAppointment->fresh()->toArray()
        );

        // Bug-Review F5: siehe store() -- der Lerner bemerkte eine Absage
        // eines bereits bestätigten Termins bisher nicht von selbst.
        BestEffortMail::send(
            fn () => Notification::send($examAppointment->submission->user, new PraxisExamAppointmentUpdated($examAppointment, 'cancelled')),
            "Terminabsage {$examAppointment->id}"
        );

        return back()->with('status', 'Termin abgesagt.');
    }
}
