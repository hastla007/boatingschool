<?php

namespace App\Notifications;

use App\Models\User;
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
        // Review-Runde 12: Team-Mitglieder werden gesiezt.
        $formal = $notifiable instanceof User && $notifiable->prefersFormalAddress();
        $email = preg_replace('/([\\\\`*_{}\[\]#|>~])/', '\\\\$1', $this->newEmail);

        return (new MailMessage)
            ->subject($formal ? 'Änderung Ihrer E-Mail-Adresse angefordert' : 'Änderung deiner E-Mail-Adresse angefordert')
            ->line(($formal ? 'Für Ihr Konto' : 'Für dein Konto')." wurde eine Änderung der Login-E-Mail-Adresse zu {$email} angefordert.")
            ->line('Diese Adresse wird erst aktiv, wenn sie über einen an sie geschickten Link bestätigt wird – bis dahin bleibt diese (bisherige) Adresse für den Login gültig.')
            ->line($formal
                ? 'Waren Sie das nicht selbst, ändern Sie bitte umgehend Ihr Passwort und wenden Sie sich an den Support.'
                : 'Warst du das nicht selbst, ändere umgehend dein Passwort und wende dich an den Support deiner Bootsschule.');
    }
}
