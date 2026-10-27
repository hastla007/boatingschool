<?php

namespace App\Notifications;

use App\Models\PraxisFormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Bug-Review F5: im gesamten Ablauf von Praxis-Anmeldung, Terminen und
 * Willkommensbox wurde bisher keine einzige Mail verschickt -- eine
 * Bootsschule bemerkte eine neue Praxis-Anmeldung nur, wenn jemand aktiv
 * im Admin-Bereich nachsah. Geht an owner/admin der Bootsschule (siehe
 * Tenant::admins()).
 */
class PraxisFormSubmissionReceived extends Notification
{
    use Queueable;

    public function __construct(private readonly PraxisFormSubmission $submission) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $learnerName = $this->submission->user->name ?? 'Ein Teilnehmer';
        $courseName = $this->submission->course->name ?? 'einen Kurs';

        return (new MailMessage)
            ->subject('Neue Praxis-Anmeldung: '.$courseName)
            ->line("{$learnerName} hat sich für \"{$courseName}\" zu Praxis & Prüfung angemeldet.")
            ->action('Anmeldung ansehen', route('admin.participants.show', $this->submission->user_id))
            ->line('Bitte schlage als Nächstes einen Prüfungstermin vor.');
    }
}
