<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Anpassbare Mailtexte (Superadmin → Mailvorlagen): je Mailart ein eigener
 * Betreff sowie ein Text vor und nach dem festen Inhalt. Der feste Inhalt
 * (Daten, Links, Schaltfläche) bleibt immer erhalten, damit keine Angabe
 * versehentlich verloren geht. Platzhalter wie {schule} werden ersetzt.
 */
class MailTemplates
{
    /** @var array<string, array{label: string, subject: string, placeholders: list<string>}> */
    public const DEFINITIONS = [
        'account_invitation' => ['label' => 'Einladung mit neuem Konto (Passwort festlegen)', 'subject' => 'Einladung zu {schule}', 'placeholders' => ['schule', 'rolle']],
        'invitation' => ['label' => 'Einladung in eine Bootsschule (bestehendes Konto)', 'subject' => 'Einladung zu {schule}', 'placeholders' => ['schule']],
        'entitlement_expiring' => ['label' => 'Kurszugang läuft bald ab', 'subject' => 'Dein Kurszugang läuft am {datum} ab', 'placeholders' => ['schule', 'kurs', 'datum']],
        'coupon_redeemed' => ['label' => 'Code eingelöst / Kurs freigeschaltet', 'subject' => 'Dein Kurs ist freigeschaltet', 'placeholders' => ['kurs']],
        'willkommensbox_shipped' => ['label' => 'Willkommensbox versendet', 'subject' => 'Deine Willkommensbox ist unterwegs', 'placeholders' => []],
        'learning_reminder' => ['label' => 'Erinnerung ans Lernen (inaktive Lernende)', 'subject' => 'Weiterlernen bei {schule}', 'placeholders' => ['schule', 'tage']],
        'announcement' => ['label' => 'Mitteilung der Bootsschule', 'subject' => '{titel}', 'placeholders' => ['schule', 'titel']],
        'platform_announcement' => ['label' => 'Mitteilung des Plattformbetreibers an die Bootsschulen', 'subject' => '{titel}', 'placeholders' => ['plattform', 'schule', 'titel']],
        'praxis_appointment' => ['label' => 'Prüfungs-/Praxistermin vorgeschlagen, zurückgezogen oder abgesagt', 'subject' => 'Terminvorschlag: {termin}', 'placeholders' => ['termin']],
    ];

    /** @var array<string, mixed>|null Gespeicherte Vorlagen (einmal je Prozess geladen). */
    private static ?array $cache = null;

    /** @param array<string, string|null> $vars */
    public static function apply(MailMessage $message, string $key, array $vars = []): MailMessage
    {
        $template = self::template($key);

        if ($template['subject'] !== '') {
            $message->subject(strtr($template['subject'], self::replacements($vars, false)));
        }
        if ($template['intro'] !== '') {
            array_unshift($message->introLines, strtr($template['intro'], self::replacements($vars, true)));
        }
        if ($template['outro'] !== '') {
            $message->outroLines[] = strtr($template['outro'], self::replacements($vars, true));
        }

        return $message;
    }

    /** @return array{subject: string, intro: string, outro: string} */
    public static function template(string $key): array
    {
        self::$cache ??= rescue(fn () => PlatformSetting::query()->first()?->mail_templates ?? [], [], false);
        $stored = self::$cache[$key] ?? [];

        return [
            'subject' => trim((string) ($stored['subject'] ?? '')),
            'intro' => trim((string) ($stored['intro'] ?? '')),
            'outro' => trim((string) ($stored['outro'] ?? '')),
        ];
    }

    /** @return array<string, string> */
    private static function replacements(array $vars, bool $markdown): array
    {
        $map = [];
        foreach ($vars as $name => $value) {
            $value = (string) $value;
            // Werte aus Daten (Schul-/Kursnamen) nie als Markdown/Link interpretieren.
            $map['{'.$name.'}'] = $markdown ? preg_replace('/([\\\\`*_{}\[\]()#+\-.!|>~])/', '\\\\$1', $value) : $value;
        }

        return $map;
    }

    /** Nach dem Speichern im Superadmin neu laden. */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
