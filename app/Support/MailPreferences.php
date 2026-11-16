<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email;

/**
 * E-Mail-Benachrichtigungen, die Lernende abbestellen können (§ 7 UWG):
 * Lern-Erinnerungen, Hinweise vor Ablauf des Kurszugangs samt
 * Verlängerungsangebot und Mitteilungen der Bootsschule. Gespeichert in
 * app_user.mail_preferences (fehlender Schlüssel = erwünscht).
 * Sicherheits- und Kontomails (Passwort, Anmeldung, Einladung, Bestätigung)
 * sind davon nicht betroffen.
 *
 * Jede dieser Mails trägt einen signierten Abmeldelink ohne Anmeldung
 * (MailUnsubscribeController) sichtbar im Text und als Header
 * List-Unsubscribe / List-Unsubscribe-Post (RFC 8058, Ein-Klick-Abmeldung).
 */
final class MailPreferences
{
    public const LEARNING_REMINDERS = 'learning_reminders';

    public const EXPIRY = 'expiry';

    public const ANNOUNCEMENTS = 'announcements';

    /** Kategorie => Bezeichnung im Profil. */
    public const CATEGORIES = [
        self::LEARNING_REMINDERS => 'Lern-Erinnerungen',
        self::EXPIRY => 'Hinweise vor Ablauf des Kurszugangs und Verlängerungsangebote',
        self::ANNOUNCEMENTS => 'Mitteilungen der Bootsschule',
    ];

    public static function wants(mixed $notifiable, string $category): bool
    {
        if (! $notifiable instanceof User) {
            return true;
        }

        return (bool) (($notifiable->mail_preferences ?? [])[$category] ?? true);
    }

    /** @return array<string, bool> */
    public static function forUser(User $user): array
    {
        return collect(self::CATEGORIES)->mapWithKeys(fn ($label, $category) => [$category => self::wants($user, $category)])->all();
    }

    /** Neue Einstellungen (noch nicht gespeichert). */
    public static function apply(User $user, array $wanted): User
    {
        $preferences = $user->mail_preferences ?? [];
        foreach ($wanted as $category => $value) {
            if (array_key_exists($category, self::CATEGORIES)) {
                $preferences[$category] = (bool) $value;
            }
        }

        return $user->forceFill(['mail_preferences' => $preferences]);
    }

    public static function unsubscribeUrl(User $user, string $category, ?Tenant $tenant): string
    {
        return CanonicalUrl::signedRoute('mail.unsubscribe', ['user' => $user->id, 'category' => $category], $tenant);
    }

    /** Sichtbare Abmeldezeile und Header für Ein-Klick-Abmeldung. */
    public static function addUnsubscribe(MailMessage $message, mixed $notifiable, string $category, ?Tenant $tenant): MailMessage
    {
        if (! $notifiable instanceof User) {
            return $message;
        }

        $url = self::unsubscribeUrl($notifiable, $category, $tenant);

        return $message
            ->line('Du möchtest diese E-Mails ('.self::CATEGORIES[$category].') nicht mehr erhalten? [Abmelden]('.$url.') – oder passe deine E-Mail-Benachrichtigungen jederzeit in deinem Profil an.')
            ->withSymfonyMessage(function (Email $email) use ($url) {
                $email->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$url.'>');
                $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }
}
