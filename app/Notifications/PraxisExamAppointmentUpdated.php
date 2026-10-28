<?php

namespace App\Notifications;

use App\Models\PraxisExamAppointment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Bug-Review F5: ein neuer Terminvorschlag, ein zurückgezogener Vorschlag
 * oder ein abgesagter, bereits fixierter Termin lösten bisher keine Mail
 * an den Lerner aus -- er bemerkte das nur, wenn er von selbst wieder auf
 * die Praxis-Seite seines Kurses sah. Geht an den Lerner der betroffenen
 * Praxis-Anmeldung.
 */
class PraxisExamAppointmentUpdated extends Notification
{
    use Queueable;

    /** @param  'proposed'|'withdrawn'|'cancelled'  $action */
    public function __construct(
        private readonly PraxisExamAppointment $appointment,
        private readonly string $action,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $courseUrl = route('praxis-pruefung.index', $this->appointment->submission->course_id);

        return match ($this->action) {
            'proposed' => (new MailMessage)
                ->subject('Terminvorschlag: '.$this->appointment->label)
                ->line('Deine Bootsschule hat dir einen Termin vorgeschlagen: "'.$this->appointment->label.'" am '.$this->appointment->formattedRange().' Uhr.')
                ->action('Termin ansehen und antworten', $courseUrl),
            'withdrawn' => (new MailMessage)
                ->subject('Terminvorschlag zurückgezogen: '.$this->appointment->label)
                ->line('Deine Bootsschule hat den Terminvorschlag "'.$this->appointment->label.'" ('.$this->appointment->formattedRange().' Uhr) zurückgezogen.')
                ->line('Du bekommst in Kürze einen neuen Vorschlag.')
                ->action('Status ansehen', $courseUrl),
            'cancelled' => (new MailMessage)
                ->subject('Termin abgesagt: '.$this->appointment->label)
                ->line('Deine Bootsschule hat den bereits bestätigten Termin "'.$this->appointment->label.'" ('.$this->appointment->formattedRange().' Uhr) abgesagt.')
                ->line('Du bekommst in Kürze einen neuen Vorschlag.')
                ->action('Status ansehen', $courseUrl),
        };
    }
}
