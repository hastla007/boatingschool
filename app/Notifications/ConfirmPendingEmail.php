<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Bestätigt eine neue, im Profil eingetragene E-Mail-Adresse, bevor sie
 * live geschaltet wird (siehe ProfileController, Sicherheitsreview H2) --
 * geht an die NEUE Adresse, nicht an die alte, damit nur deren Besitzer den
 * Wechsel bestätigen kann.
 */
class ConfirmPendingEmail extends Notification
{
    use Queueable;

    public function __construct(private readonly string $pendingEmail)
    {
    }

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'profile.email.confirm',
            now()->addMinutes(60),
            ['id' => $notifiable->getKey(), 'hash' => sha1($this->pendingEmail)]
        );

        return (new MailMessage)
            ->subject('Neue E-Mail-Adresse bestätigen')
            ->line('Für dein Konto wurde diese E-Mail-Adresse als neue Login-Adresse hinterlegt.')
            ->action('E-Mail-Adresse bestätigen', $url)
            ->line('Hast du diese Änderung nicht selbst vorgenommen, kannst du diese Nachricht einfach ignorieren -- ohne Bestätigung ändert sich nichts an deinem Konto.');
    }
}
