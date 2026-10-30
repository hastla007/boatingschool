<?php

namespace App\Http\Controllers;

use App\Models\CourseDefinition;
use App\Models\PraxisExamAppointment;
use App\Models\Tenant;
use App\Notifications\PraxisExamAppointmentResponded;
use App\Services\EntitlementService;
use App\Services\PraxisExamAppointmentService;
use App\Support\BestEffortMail;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
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
    public function respond(Request $request, TenantContext $tenantContext, CourseDefinition $course, PraxisExamAppointment $examAppointment, PraxisExamAppointmentService $appointments, EntitlementService $entitlements): Response
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

        // Bug-Review (Prüfungstermin bestätigbar trotz bereits entzogenem
        // Kurszugang): PraxisPruefungController prüft das Entitlement nur
        // beim Aufruf der Seite (siehe dort, EntitlementService::
        // hasAccess()) -- ein Admin kann den Kurszugang aber JEDERZEIT
        // zwischen Seitenaufruf und Absenden widerrufen (siehe W3/T5 für
        // dasselbe Muster bei der Coupon-Einlösung). respond() selbst
        // prüfte das nie, sodass ein Lerner einen Terminvorschlag auch
        // nach einem Widerruf noch annehmen oder ablehnen konnte.
        abort_unless($entitlements->hasAccess($tenantContext->tenant(), $user, $course), 403, 'Für diesen Kurs liegt kein aktives Entitlement mehr vor.');

        $validated = $request->validate([
            'decision' => ['required', 'in:accepted,declined'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $appointments->respond($examAppointment, $validated['decision'], $validated['comment'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        // Bug-Review F5: im gesamten Ablauf von Praxis-Anmeldung, Terminen
        // und Willkommensbox wurde bisher keine Mail verschickt -- die
        // Bootsschule bemerkte eine Rückmeldung bisher nur, wenn jemand
        // aktiv im Admin-Bereich nachsah.
        $admins = Tenant::find($examAppointment->tenant_id)?->admins() ?? collect();
        if ($admins->isNotEmpty()) {
            BestEffortMail::send(
                fn () => Notification::send($admins, new PraxisExamAppointmentResponded($examAppointment)),
                "Rückmeldung {$examAppointment->id}"
            );
        }

        $status = $validated['decision'] === 'accepted'
            ? '"'.$examAppointment->label.'" bestätigt.'
            : 'Rückmeldung gespeichert -- deine Bootsschule schlägt dir einen neuen Termin vor.';

        return redirect()->route('praxis-pruefung.index', $course)->with('status', $status);
    }
}
