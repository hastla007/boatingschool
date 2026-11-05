<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Hinweis an die Person, deren Zwei-Faktor-Anmeldung der Plattform-Support
 * zurückgesetzt hat -- war sie es nicht selbst, die darum gebeten hat,
 * merkt sie es so sofort.
 */
class TwoFactorResetBySupport extends Notification
{
    use Queueable;

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Deine Zwei-Faktor-Anmeldung wurde zurückgesetzt')
            ->line('Der Support hat die Zwei-Faktor-Anmeldung deines Kontos ausgeschaltet. Du meldest dich jetzt wieder nur mit E-Mail und Passwort an.')
            ->line('Bitte richte sie nach der nächsten Anmeldung im Profil neu ein.')
            ->line('Hast du das nicht veranlasst? Dann ändere bitte sofort dein Passwort und melde dich beim Support.');
    }
}
