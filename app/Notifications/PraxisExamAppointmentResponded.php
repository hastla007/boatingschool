<?php

namespace App\Notifications;

use App\Models\PraxisExamAppointment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Bug-Review F5: nimmt ein Lerner einen Terminvorschlag an oder lehnt ihn
 * ab, bemerkte die Bootsschule das bisher nicht von selbst -- erst ein
 * Blick in den Admin-Bereich zeigte die Rückmeldung. Geht an owner/admin
 * der Bootsschule (siehe Tenant::admins()).
 */
class PraxisExamAppointmentResponded extends Notification
{
    use Queueable;

    public function __construct(private readonly PraxisExamAppointment $appointment) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $learnerName = $this->appointment->submission->user->name ?? 'Ein Teilnehmer';
        $verb = $this->appointment->response === 'accepted' ? 'angenommen' : 'abgelehnt';

        $message = (new MailMessage)
            ->subject('Rückmeldung zu "'.$this->appointment->label.'"')
            ->line("{$learnerName} hat den Termin \"{$this->appointment->label}\" ({$this->appointment->formattedRange()} Uhr) {$verb}.");

        if ($this->appointment->response_comment) {
            $message->line('Kommentar: "'.$this->appointment->response_comment.'"');
        }

        return $message->action('Anmeldung ansehen', route('admin.participants.show', $this->appointment->submission->user_id));
    }
}
