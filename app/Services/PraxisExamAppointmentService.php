<?php

namespace App\Services;

use App\Models\PraxisExamAppointment;
use App\Models\PraxisFormSubmission;
use App\Models\User;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Terminvorschlagsrunden für die Prüfung zu einer Praxis-Anmeldung: die
 * Bootsschule schlägt einen Termin vor, der Lerner nimmt an oder lehnt ab
 * -- bei Ablehnung folgt eine neue Runde, bis ein Termin angenommen und
 * damit fixiert wird. Geteilt zwischen Admin\PraxisExamAppointmentController
 * (Vorschlag) und PraxisExamAppointmentController (Rückmeldung des Lerners).
 */
class PraxisExamAppointmentService
{
    public function propose(PraxisFormSubmission $submission, User $admin, CarbonInterface $examDate): PraxisExamAppointment
    {
        if ($submission->isExamDateConfirmed()) {
            throw new RuntimeException('Für diese Anmeldung ist bereits ein fixer Prüfungstermin bestätigt.');
        }

        $latest = $submission->latestExamAppointment();

        if ($latest && $latest->isPending()) {
            throw new RuntimeException('Es wartet noch ein Terminvorschlag auf Rückmeldung des Teilnehmers.');
        }

        return PraxisExamAppointment::create([
            'tenant_id' => $submission->tenant_id,
            'praxis_form_submission_id' => $submission->id,
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

        if ($decision === 'accepted') {
            $appointment->submission->update(['confirmed_exam_date' => $appointment->exam_date]);
        }
    }
}
