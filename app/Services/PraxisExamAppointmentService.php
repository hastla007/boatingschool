<?php

namespace App\Services;

use App\Models\PraxisExamAppointment;
use App\Models\PraxisFormSubmission;
use App\Models\User;
use Carbon\CarbonInterface;
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
    ): PraxisExamAppointment {
        if ($endsAt && $endsAt->lessThanOrEqualTo($examDate)) {
            throw new RuntimeException('Das Ende muss nach dem Beginn liegen.');
        }

        if ($slot === null) {
            if (! $submission->isExamDateConfirmed()) {
                throw new RuntimeException('Der Prüfungstermin muss zuerst fixiert werden, bevor weitere Termine vorgeschlagen werden können.');
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
            'slot' => $slot,
            'label' => $label,
            'round' => $round,
            'exam_date' => $examDate,
            'ends_at' => $endsAt,
            'proposed_by_user_id' => $admin->id,
        ]);
    }

    public function respond(PraxisExamAppointment $appointment, string $decision, ?string $comment): void
    {
        if (! $appointment->isPending()) {
            throw new RuntimeException('Zu diesem Terminvorschlag liegt bereits eine Rückmeldung vor.');
        }

        $appointment->update([
            'response' => $decision,
            'response_comment' => $comment,
            'responded_at' => now(),
        ]);

        // Zusätzliche Termine (slot >= 1) brauchen keine eigene Spalte --
        // ihr fixierter Termin wird direkt aus dem angenommenen Datensatz
        // gelesen (siehe PraxisFormSubmission::isPracticeAppointmentConfirmed).
        if ($decision === 'accepted' && $appointment->slot === 0) {
            $appointment->submission->update(['confirmed_exam_date' => $appointment->exam_date]);
        }
    }
}
