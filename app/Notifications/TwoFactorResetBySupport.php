<?php

namespace App\Notifications;

use App\Models\User;
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
        // Review-Runde 12: Team-Mitglieder werden gesiezt.
        if ($notifiable instanceof User && $notifiable->prefersFormalAddress()) {
            return (new MailMessage)
                ->subject('Ihre Zwei-Faktor-Anmeldung wurde zurückgesetzt')
                ->line('Der Support hat die Zwei-Faktor-Anmeldung Ihres Kontos ausgeschaltet. Sie melden sich jetzt wieder nur mit E-Mail und Passwort an.')
                ->line('Bitte richten Sie sie nach der nächsten Anmeldung im Profil neu ein.')
                ->line('Haben Sie das nicht veranlasst? Dann ändern Sie bitte sofort Ihr Passwort und melden Sie sich beim Support.');
        }

        return (new MailMessage)
            ->subject('Deine Zwei-Faktor-Anmeldung wurde zurückgesetzt')
            ->line('Der Support hat die Zwei-Faktor-Anmeldung deines Kontos ausgeschaltet. Du meldest dich jetzt wieder nur mit E-Mail und Passwort an.')
            ->line('Bitte richte sie nach der nächsten Anmeldung im Profil neu ein.')
            ->line('Hast du das nicht veranlasst? Dann ändere bitte sofort dein Passwort und melde dich beim Support.');
    }
}
