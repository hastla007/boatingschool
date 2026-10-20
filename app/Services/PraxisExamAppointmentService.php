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
 * fixiert, kann die Bootsschule je nach Kurs zusätzlich eine oder mehrere
 * unabhängige Praxistermin-Runden vorschlagen (slot 1, 2, ..., Anzahl aus
 * PraxisFormTemplate::practice_appointment_count) -- jeder Slot läuft
 * dabei genauso lange über beliebig viele Runden, bis er fixiert ist.
 * Geteilt zwischen Admin\PraxisExamAppointmentController (Vorschlag) und
 * PraxisExamAppointmentController (Rückmeldung des Lerners).
 */
class PraxisExamAppointmentService
{
    public function propose(PraxisFormSubmission $submission, User $admin, CarbonInterface $examDate, int $slot = 0): PraxisExamAppointment
    {
        if ($slot === 0) {
            if ($submission->isExamDateConfirmed()) {
                throw new RuntimeException('Für diese Anmeldung ist bereits ein fixer Prüfungstermin bestätigt.');
            }
        } else {
            if (! $submission->isExamDateConfirmed()) {
                throw new RuntimeException('Der Prüfungstermin muss zuerst fixiert werden, bevor zusätzliche Praxistermine vorgeschlagen werden können.');
            }

            $requiredCount = $submission->requiredPracticeAppointmentCount();

            if ($slot > $requiredCount) {
                throw new RuntimeException("Für diesen Kurs sind nur {$requiredCount} zusätzliche Praxistermine vorgesehen.");
            }

            if ($submission->isPracticeAppointmentConfirmed($slot)) {
                throw new RuntimeException('Für diesen Praxistermin liegt bereits eine Bestätigung vor.');
            }
        }

        $latest = $submission->latestAppointmentForSlot($slot);

        if ($latest && $latest->isPending()) {
            throw new RuntimeException('Es wartet noch ein Terminvorschlag auf Rückmeldung des Teilnehmers.');
        }

        return PraxisExamAppointment::create([
            'tenant_id' => $submission->tenant_id,
            'praxis_form_submission_id' => $submission->id,
            'slot' => $slot,
            'round' => ($latest?->round ?? 0) + 1,
            'exam_date' => $examDate,
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

        // Praxistermine (slot >= 1) brauchen keine eigene Spalte -- ihr
        // fixierter Termin wird direkt aus dem angenommenen Datensatz
        // gelesen (siehe PraxisFormSubmission::confirmedPracticeDate).
        if ($decision === 'accepted' && $appointment->slot === 0) {
            $appointment->submission->update(['confirmed_exam_date' => $appointment->exam_date]);
        }
    }
}
