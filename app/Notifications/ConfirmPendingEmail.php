<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\CanonicalUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Bestätigt eine neue, im Profil eingetragene E-Mail-Adresse, bevor sie
 * live geschaltet wird (siehe ProfileController, Sicherheitsreview H2) --
 * geht an die NEUE Adresse, nicht an die alte, damit nur deren Besitzer den
 * Wechsel bestätigen kann.
 *
 * Bug-Review H2 (Folgefehler, kritisch): $notifiable ist beim Versand über
 * Notification::route('mail', $pendingEmail) (siehe ProfileController) ein
 * anonymer AnonymousNotifiable OHNE getKey() -- die Nutzer-ID für den
 * signierten Bestätigungslink kommt deshalb jetzt explizit aus dem
 * Konstruktor statt aus $notifiable. Vorher landete dieselbe Notification
 * per $user->notify(...) immer im Postfach der AKTUELLEN (alten) Adresse
 * -- der Besitz der neuen Adresse wurde dadurch nie geprüft, der Nutzer
 * konnte jede fremde Adresse selbst anklicken und für sein eigenes Konto
 * "bestätigen" (Vorab-Übernahme wie in Sicherheitsreview H2, nur über das
 * Profil statt über die Registrierung).
 */
class ConfirmPendingEmail extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $userId,
        private readonly string $pendingEmail,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        // Kanonischer Host statt des Request-Hosts (Custom Domain), siehe CanonicalUrl.
        $url = CanonicalUrl::temporarySignedRoute(
            'profile.email.confirm',
            now()->addMinutes(60),
            ['id' => $this->userId, 'hash' => sha1($this->pendingEmail)],
            app()->bound('currentTenant') ? app('currentTenant') : null,
        );

        // Review-Runde 12: Team-Mitglieder werden gesiezt (Empfänger ist hier
        // die neue Adresse, nicht das Konto selbst).
        $formal = (bool) User::on('pgsql_admin')->find($this->userId)?->prefersFormalAddress();

        return (new MailMessage)
            ->subject('Neue E-Mail-Adresse bestätigen')
            ->line($formal
                ? 'Für Ihr Konto wurde diese E-Mail-Adresse als neue Login-Adresse hinterlegt.'
                : 'Für dein Konto wurde diese E-Mail-Adresse als neue Login-Adresse hinterlegt.')
            ->action('E-Mail-Adresse bestätigen', $url)
            ->line($formal
                ? 'Haben Sie diese Änderung nicht selbst vorgenommen, können Sie diese Nachricht einfach ignorieren – ohne Bestätigung ändert sich nichts an Ihrem Konto.'
                : 'Hast du diese Änderung nicht selbst vorgenommen, kannst du diese Nachricht einfach ignorieren – ohne Bestätigung ändert sich nichts an deinem Konto.');
    }
}
