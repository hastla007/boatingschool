<?php

namespace App\Http\Controllers;

use App\Models\CourseDefinition;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\TenantCourseLocation;
use App\Notifications\PraxisFormSubmissionReceived;
use App\Services\CourseProgressService;
use App\Services\EntitlementService;
use App\Services\PraxisFormDataResolver;
use App\Support\BestEffortMail;
use App\Support\ExamRegistration;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nimmt das ausgefüllte Praxisformular (siehe PraxisFormTemplate) entgegen
 * -- Validierungsregeln werden aus den Feld-Definitionen der Vorlage
 * abgeleitet, da jede Vorlage ihre eigenen Felder hat. Die Daten dienen der
 * Bootsschule als Grundlage für die Anmeldung bei der Prüfungskommission;
 * die Bootsschule selbst kann sie nachträglich korrigieren oder die
 * Anmeldung löschen (siehe Admin\PraxisFormSubmissionController, Bug-Review
 * F6), der Lerner selbst aber weiterhin nicht.
 */
class PraxisFormSubmissionController extends Controller
{
    public function store(
        Request $request,
        TenantContext $tenantContext,
        EntitlementService $entitlements,
        CourseProgressService $courseProgress,
        PraxisFormDataResolver $dataResolver,
        CourseDefinition $course,
    ): Response {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403, 'Für diesen Kurs liegt kein aktives Entitlement vor.');
        abort_if(ExamRegistration::isSelf($tenant, $course), 404, 'Für diesen Kurs meldest du dich selbst zur Prüfung an.');

        $template = PraxisFormTemplate::where('active', true)
            ->whereJsonContains('course_ids', $course->id)
            ->first();

        abort_unless($template, 404, 'Für diesen Kurs ist aktuell kein Praxisformular hinterlegt.');

        // Bug-Review (Gates vergleichen den gerundeten Wert): overallPercent()
        // rundet für die Anzeige -- 49,5% gefestigte Fragen zeigten sich
        // bisher als "50%" und erfüllten damit fälschlich eine Schwelle von
        // 50%. exactPercent() vergleicht den tatsächlichen, ungerundeten
        // Fortschritt.
        $percent = $courseProgress->exactPercent($course, $tenant, $user);
        abort_unless($percent >= $template->min_progress_percent, 403, "Für die Anmeldung werden mindestens {$template->min_progress_percent}% Kursfortschritt benötigt.");

        // Gleiches Gate wie PraxisPruefungController::index() -- verhindert,
        // dass ein Formular abgeschickt wird, dessen Tab seit vor dem
        // Vervollständigen des Profils offen war.
        abort_unless($user->hasCompleteRegistrationProfile($tenant), 403, 'Bitte vervollständige zuerst dein Profil (Adresse und Geburtsdatum).');

        $existing = PraxisFormSubmission::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->exists();

        if ($existing) {
            return back()->with('status', 'Du hast dich für diesen Kurs bereits angemeldet.');
        }

        $locations = TenantCourseLocation::where('tenant_id', $tenant->id)
            ->whereJsonContains('course_ids', $course->id)
            ->with('pruefungsausschuss')
            ->get();

        $validated = $request->validate($dataResolver->rulesFor($template, $locations));
        $data = $dataResolver->resolveLocationFields($template, $locations, $validated, $dataResolver->allCourses());

        // Bug-Review N16: zwischen der Prüfung oben ("noch nicht
        // angemeldet?") und dem eigentlichen INSERT lag kein Lock -- ein
        // Doppelklick auf "Absenden" konnte zwei Anmeldungen für denselben
        // Kurs anlegen und verletzte dann den Unique-Index (tenant_id,
        // user_id, course_id), was als uncaught QueryException in einem
        // HTTP 500 endete. Der Advisory-Lock serialisiert das und prüft
        // "bereits angemeldet" direkt davor noch einmal, analog zu
        // Bug-Review B14 (EntitlementController).
        //
        // Bug-Review (Mails synchron in Transaktion mit Lock): der
        // synchrone Mailversand (siehe BestEffortMail) lief hier bisher
        // INNERHALB dieser Transaktion, die zusätzlich den Advisory-Lock
        // oben hält -- ein langsamer oder nicht erreichbarer Mailserver
        // hielt damit Transaktion und Lock unnötig lange offen und
        // blockierte dadurch jeden anderen Request, der denselben Lock für
        // dieselbe Anmeldung erwerben wollte. Transaktion/Lock umfassen
        // jetzt nur noch den eigentlichen INSERT; der Mailversand läuft
        // erst danach, analog zu allen anderen Mailversänden in dieser
        // Anwendung (z. B. Admin\PraxisExamAppointmentController).
        $submission = DB::transaction(function () use ($tenant, $course, $user, $template, $data) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$tenant->id.':'.$user->id.':'.$course->id]);

            $stillExisting = PraxisFormSubmission::where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->exists();

            if ($stillExisting) {
                return null;
            }

            return PraxisFormSubmission::create([
                'tenant_id' => $tenant->id,
                'course_id' => $course->id,
                'user_id' => $user->id,
                'praxis_form_template_id' => $template->id,
                'data' => $data,
            ]);
        });

        if ($submission === null) {
            return back()->with('status', 'Du hast dich für diesen Kurs bereits angemeldet.');
        }

        // Bug-Review F5: im gesamten Ablauf von Praxis-Anmeldung, Terminen
        // und Willkommensbox wurde bisher keine Mail verschickt -- eine
        // Bootsschule bemerkte eine neue Praxis-Anmeldung nur, wenn jemand
        // aktiv im Admin-Bereich nachsah.
        $admins = $tenant->admins();
        if ($admins->isNotEmpty()) {
            BestEffortMail::send(
                fn () => Notification::send($admins, new PraxisFormSubmissionReceived($submission)),
                "Praxis-Anmeldung {$submission->id} an Bootsschule {$tenant->id}"
            );
        }

        return redirect()->route('praxis-pruefung.index', $course);
    }
}
