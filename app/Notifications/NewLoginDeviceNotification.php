<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Hinweis an Team-Mitglieder und Superadmins: Anmeldung von einem bisher
 * unbekannten Gerät (siehe App\Services\NewLoginDeviceNotifier). Nur an
 * Konten mit erhöhten Rechten, deshalb durchgehend gesiezt.
 */
class NewLoginDeviceNotification extends Notification
{
    use Queueable;

    public function __construct(
        public CarbonInterface $at,
        public string $browser,
        public string $os,
        public string $maskedIp,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Neue Anmeldung bei Ihrem Konto')
            ->line('Ihr Konto wurde soeben auf einem Gerät angemeldet, das wir bisher nicht kannten.')
            ->line('Zeitpunkt: '.$this->at->copy()->timezone('Europe/Berlin')->format('d.m.Y, H:i').' Uhr')
            ->line('Gerät: '.$this->browser.' unter '.$this->os)
            ->line('Ungefähre IP-Adresse: '.$this->maskedIp)
            ->line('Waren Sie das selbst? Dann ist nichts weiter zu tun.')
            ->line('Waren Sie das nicht? Dann ändern Sie bitte sofort Ihr Passwort und melden Sie sich beim Support.');
    }
}
