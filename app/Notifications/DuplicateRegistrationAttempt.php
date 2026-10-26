<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Bug-Review R4-F: der Duplikat-Zweig in RegisteredUserController::store()
 * verschickte bislang keine E-Mail -- nur der Neukonto-Zweig löste
 * synchron den echten, über SMTP merklich teuren Versand der
 * Verifizierungsmail aus (Registered-Event). Trotz identischem
 * Antworttext/-struktur (Bug-Review M3, Folgefehler) blieb die
 * Registrierung dadurch über die Antwortzeit weiter unterscheidbar: ein
 * synchroner SMTP-Versand dominiert jede lokale Rechenzeit (z. B. den
 * Dummy-Hash direkt oberhalb dieses Zweigs) deutlich. Diese Mail an die
 * BEREITS bestehende Adresse gleicht die Laufzeit an und hat zugleich
 * einen echten Zweck: der tatsächliche Kontoinhaber erfährt von einem
 * Registrierungsversuch mit seiner Adresse.
 */
class DuplicateRegistrationAttempt extends Notification
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
            ->subject('Registrierungsversuch mit deiner E-Mail-Adresse')
            ->line('Jemand hat versucht, mit dieser E-Mail-Adresse ein neues Konto zu registrieren -- für diese Adresse existiert aber bereits ein Konto.')
            ->line('Warst du das selbst? Dann melde dich einfach ganz normal an oder setze dein Passwort zurück, falls du es vergessen hast.')
            ->line('Warst du das nicht, kannst du diese Nachricht ignorieren -- an deinem Konto wurde nichts verändert.');
    }
}
