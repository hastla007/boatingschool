<?php

namespace App\Services;

use App\Models\PraxisExamAppointment;
use App\Models\PraxisFormSubmission;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Terminvorschlagsrunden zu einer Praxis-Anmeldung: die Bootsschule
 * schlägt einen Termin vor, der Lerner nimmt an oder lehnt ab -- bei
 * Ablehnung folgt eine neue Runde, bis ein Termin angenommen und damit
 * fixiert wird. Slot 0 ist dabei der Prüfungstermin selbst; ist dieser
 * fixiert, kann die Bootsschule beliebig viele zusätzliche, frei benannte
 * Termine anlegen (slot 1, 2, ..., z. B. "Kleine Praxisstunde (1h)" oder
 * "Navigationsstunde") -- es gibt kein festes Kontingent, die Bootsschule
 * entscheidet selbst, wie viele sie braucht. Jeder Slot läuft dabei
 * genauso lange über beliebig viele Runden, bis er fixiert ist, und kann
 * optional einen Zeitraum (Beginn/Ende, z. B. 16:00-18:00) statt nur
 * eines Zeitpunkts haben. Geteilt zwischen
 * Admin\PraxisExamAppointmentController (Vorschlag) und
 * PraxisExamAppointmentController (Rückmeldung des Lerners).
 */
class PraxisExamAppointmentService
{
    /**
     * $slot = null legt einen neuen, frei benannten Termin an (nächste freie
     * Slot-Nummer, $label Pflicht). $slot = 0 ist der Prüfungstermin. Jeder
     * andere $slot setzt eine neue Runde für einen bereits bestehenden
     * Termin an (dessen Bezeichnung bleibt erhalten, $label wird ignoriert).
     */
    public function propose(
        PraxisFormSubmission $submission,
        User $admin,
        CarbonInterface $examDate,
        ?CarbonInterface $endsAt = null,
        ?int $slot = null,
        ?string $label = null,
        ?string $tenantCourseLocationId = null,
        bool $isOnline = false,
        ?string $onlineMeetingUrl = null,
        ?string $description = null,
    ): PraxisExamAppointment {
        if ($endsAt && $endsAt->lessThanOrEqualTo($examDate)) {
            throw new RuntimeException('Das Ende muss nach dem Beginn liegen.');
        }

        // Bug-Review F4: exam_date wurde bisher nur als gültiges Datum
        // validiert (siehe Admin\PraxisExamAppointmentController), nicht
        // als ZUKÜNFTIGES -- ein Termin ließ sich damit auch für einen
        // bereits vergangenen Zeitpunkt vorschlagen (und anschließend vom
        // Lerner "annehmen").
        if ($examDate->isPast()) {
            throw new RuntimeException('Der Termin muss in der Zukunft liegen.');
        }

        // Bug-Review N16: zwischen den Prüfungen unten (bereits fixiert?
        // noch ein Vorschlag offen? nächste freie Slot-Nummer?) und dem
        // eigentlichen INSERT lag kein Lock -- ein Doppelklick auf
        // "Vorschlagen" konnte zwei Zeilen mit derselben Slot/Runden-
        // Kombination anlegen und verletzte dann den Unique-Index
        // (praxis_form_submission_id, slot, round), was als uncaught
        // QueryException in einem HTTP 500 endete. Der Advisory-Lock
        // serialisiert das pro Anmeldung, analog zu Bug-Review B14
        // (EntitlementController) und B4 (ExamController::startPaper()).
        return DB::transaction(function () use ($submission, $admin, $examDate, $endsAt, $slot, $label, $tenantCourseLocationId, $isOnline, $onlineMeetingUrl, $description) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$submission->id]);

            return $this->proposeLocked($submission, $admin, $examDate, $endsAt, $slot, $label, $tenantCourseLocationId, $isOnline, $onlineMeetingUrl, $description);
        });
    }

    private function proposeLocked(
        PraxisFormSubmission $submission,
        User $admin,
        CarbonInterface $examDate,
        ?CarbonInterface $endsAt,
        ?int $slot,
        ?string $label,
        ?string $tenantCourseLocationId,
        bool $isOnline,
        ?string $onlineMeetingUrl,
        ?string $description,
    ): PraxisExamAppointment {
        if ($slot === null) {
            if (! $submission->isExamDateConfirmed()) {
                throw new RuntimeException('Der Prüfungstermin muss zuerst fixiert werden, bevor weitere Termine vorgeschlagen werden können.');
            }

            // Bug-Review F4: Zusatztermine (Praxisstunden, Navigationsstunde
            // o. ä.) bauen inhaltlich auf dem fixierten Prüfungstermin auf --
            // ohne diese Prüfung ließ sich trotzdem ein Zusatztermin VOR dem
            // Prüfungstermin anlegen.
            if ($examDate->lessThan($submission->confirmed_exam_date)) {
                throw new RuntimeException('Dieser Termin darf nicht vor dem fixierten Prüfungstermin liegen.');
            }

            if (trim((string) $label) === '') {
                throw new RuntimeException('Bitte eine Bezeichnung für den Termin angeben.');
            }

            $slot = $submission->nextPracticeSlotNumber();
            $round = 1;
        } else {
            if ($slot === 0) {
                if ($submission->isExamDateConfirmed()) {
                    throw new RuntimeException('Für diese Anmeldung ist bereits ein fixer Prüfungstermin bestätigt.');
                }

                $label = 'Prüfungstermin';
            } elseif ($submission->isPracticeAppointmentConfirmed($slot)) {
                throw new RuntimeException('Für diesen Termin liegt bereits eine Bestätigung vor.');
            } else {
                $label = $submission->labelForSlot($slot) ?? $label;
            }

            $latest = $submission->latestAppointmentForSlot($slot);

            if ($latest && $latest->isPending()) {
                throw new RuntimeException('Es wartet noch ein Terminvorschlag auf Rückmeldung des Teilnehmers.');
            }

            $round = ($latest?->round ?? 0) + 1;
        }

        return PraxisExamAppointment::create([
            'tenant_id' => $submission->tenant_id,
            'praxis_form_submission_id' => $submission->id,
            'tenant_course_location_id' => $isOnline ? null : $tenantCourseLocationId,
            'is_online' => $isOnline,
            'online_meeting_url' => $isOnline ? $onlineMeetingUrl : null,
            'slot' => $slot,
            'label' => $label,
            'round' => $round,
            'exam_date' => $examDate,
            'ends_at' => $endsAt,
            'description' => $description,
            'proposed_by_user_id' => $admin->id,
        ]);
    }

    /**
     * Bug-Review F4: zwei Tabs/Geräte, die beide "Annehmen"/"Ablehnen" auf
     * denselben Terminvorschlag schicken, lasen hier bisher beide
     * isPending() = true, bevor eine der beiden Anfragen überhaupt
     * geschrieben hatte (klassisches Read-Then-Write-Race) -- die zweite
     * Anfrage überschrieb die erste stillschweigend, ohne jeden Fehler.
     * Bei "angenommen" gefolgt von "abgelehnt" blieb dadurch sogar
     * praxis_form_submission.confirmed_exam_date gesetzt, während der
     * Termin selbst als abgelehnt markiert war -- ein inkonsistenter
     * Zustand. Der Advisory-Lock serialisiert das pro Termin (analog zu
     * propose() oben, pro Anmeldung): die zweite Anfrage sieht nach dem
     * Erwerb des Locks den von der ersten bereits geschriebenen Stand und
     * bricht über die bestehende isPending()-Prüfung kontrolliert ab,
     * statt ihn stillschweigend zu überschreiben.
     */
    public function respond(PraxisExamAppointment $appointment, string $decision, ?string $comment): void
    {
        DB::transaction(function () use ($appointment, $decision, $comment) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$appointment->id]);
            $appointment->refresh();

            if (! $appointment->isPending()) {
                throw new RuntimeException('Zu diesem Terminvorschlag liegt bereits eine Rückmeldung vor.');
            }

            // Bug-Review F4: ein bereits verstrichener Termin ließ sich
            // bisher trotzdem noch annehmen (ablehnen bleibt erlaubt -- das
            // bringt unabhängig vom Zeitpunkt einen neuen Vorschlag an).
            if ($decision === 'accepted' && $appointment->exam_date->isPast()) {
                throw new RuntimeException('Dieser Termin liegt bereits in der Vergangenheit und kann nicht mehr angenommen werden -- bitte um einen neuen Vorschlag bitten.');
            }

            $appointment->update([
                'response' => $decision,
                'response_comment' => $comment,
                'responded_at' => now(),
            ]);

            // Zusätzliche Termine (slot >= 1) brauchen keine eigene Spalte
            // -- ihr fixierter Termin wird direkt aus dem angenommenen
            // Datensatz gelesen (siehe PraxisFormSubmission::
            // isPracticeAppointmentConfirmed).
            if ($decision === 'accepted' && $appointment->slot === 0) {
                $appointment->submission->update(['confirmed_exam_date' => $appointment->exam_date]);
            }
        });
    }

    /**
     * Bug-Review F4: die Bootsschule konnte einen eigenen, noch offenen
     * Vorschlag bisher nicht zurückziehen (z. B. bei einem Tippfehler im
     * Datum oder einem inzwischen nicht mehr verfügbaren Ort) -- einzige
     * Möglichkeit war, auf die Rückmeldung des Lerners zu warten. Ein
     * zurückgezogener Vorschlag räumt die Runde verbraucht, genau wie eine
     * echte Ablehnung durch den Lerner -- die nächste Runde kann sofort
     * vorgeschlagen werden.
     *
     * Bug-Review (Zurückziehen/Absagen ungesperrt gegen Annehmen): anders
     * als respond() lief diese Methode bisher ohne den Advisory-Lock aus
     * respond() -- nimmt der Lerner einen Vorschlag an, während die
     * Bootsschule praktisch gleichzeitig genau diesen Vorschlag zurückzieht,
     * konnte isPending() hier noch den alten, noch ausstehenden Stand lesen
     * und ihn anschließend auf "withdrawn" überschreiben, nachdem respond()
     * ihn bereits als "accepted" samt confirmed_exam_date fixiert hatte --
     * derselbe inkonsistente Zustand wie beim in respond() bereits
     * behobenen Race, nur über zwei verschiedene Methoden statt zwei
     * Aufrufe derselben. Derselbe Lock-Schlüssel (hashtext() über die
     * Termin-ID) serialisiert withdraw() jetzt auch gegen respond().
     */
    public function withdraw(PraxisExamAppointment $appointment): void
    {
        DB::transaction(function () use ($appointment) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$appointment->id]);
            $appointment->refresh();

            if (! $appointment->isPending()) {
                throw new RuntimeException('Dieser Vorschlag wartet nicht mehr auf eine Rückmeldung.');
            }

            $appointment->update([
                'response' => 'withdrawn',
                'responded_at' => now(),
            ]);
        });
    }

    /**
     * Bug-Review F4: ein bereits ANGENOMMENER (fixierter) Termin ließ sich
     * nicht mehr absagen -- z. B. wenn der Prüfungsausschuss den Termin
     * doch nicht wahrnehmen kann. Bei slot 0 (Prüfungstermin) wird dabei
     * auch confirmed_exam_date wieder geleert, damit ein neuer
     * Prüfungstermin vorgeschlagen werden kann (proposeLocked() lehnt
     * slot 0 sonst ab, solange isExamDateConfirmed() noch zutrifft).
     *
     * Bug-Review (Zurückziehen/Absagen ungesperrt gegen Annehmen): siehe
     * withdraw() oben -- dieselbe Lücke bestand hier gegenüber einer
     * gleichzeitigen Ablehnung durch den Lerner (respond() mit
     * "declined"): cancel() prüfte response auf einer womöglich bereits
     * veralteten Kopie, bevor respond() seinen Lock überhaupt erwarb, und
     * konnte den inzwischen abgelehnten Termin anschließend trotzdem auf
     * "cancelled" setzen und confirmed_exam_date erneut (fälschlich)
     * leeren. Derselbe Lock-Schlüssel wie in respond()/withdraw() oben.
     */
    public function cancel(PraxisExamAppointment $appointment): void
    {
        DB::transaction(function () use ($appointment) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$appointment->id]);
            $appointment->refresh();

            if ($appointment->response !== 'accepted') {
                throw new RuntimeException('Nur ein bereits angenommener Termin kann abgesagt werden.');
            }

            $appointment->update(['response' => 'cancelled']);

            if ($appointment->slot === 0) {
                $appointment->submission->update(['confirmed_exam_date' => null]);
            }
        });
    }
}
