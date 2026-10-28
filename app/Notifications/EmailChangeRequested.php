<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Bug-Review (E-Mail-Änderung ohne Hinweis an die alte Adresse): geht an
 * die BISHERIGE Adresse, sobald im Profil eine neue E-Mail-Adresse
 * eingetragen wurde (siehe ProfileController::update()) -- die neue
 * Adresse selbst wird unabhängig davon per ConfirmPendingEmail erst nach
 * eigener Bestätigung aktiv. Ohne diesen Hinweis bemerkte der Besitzer der
 * alten Adresse eine über eine entführte Session angestoßene Änderung
 * erst, wenn er sich mit seinem bisherigen Passwort nicht mehr einloggen
 * konnte.
 */
class EmailChangeRequested extends Notification
{
    use Queueable;

    public function __construct(private readonly string $newEmail) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Änderung deiner E-Mail-Adresse angefordert')
            ->line("Für dein Konto wurde eine Änderung der Login-E-Mail-Adresse zu {$this->newEmail} angefordert.")
            ->line('Diese Adresse wird erst aktiv, wenn sie über einen an sie geschickten Link bestätigt wird -- bis dahin bleibt diese (bisherige) Adresse für den Login gültig.')
            ->line('Warst du das nicht selbst, ändere umgehend dein Passwort und wende dich an den Support deiner Bootsschule.');
    }
}
