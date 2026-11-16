<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Anpassbare Mailtexte (Superadmin → Mailvorlagen): je Mailart ein eigener
 * Betreff sowie ein Text vor und nach dem festen Inhalt. Der feste Inhalt
 * (Daten, Links, Schaltfläche) bleibt immer erhalten, damit keine Angabe
 * versehentlich verloren geht. Platzhalter wie {schule} werden ersetzt.
 * Ist ein Platzhalter unbekannt (Wert null, z. B. {gruppe} bei einem aus
 * WordPress übernommenen Gruppenzugang ohne Lerngruppe), entfällt jeder
 * gespeicherte Text, der ihn verwendet -- es gilt dann der Standard (Betreff)
 * bzw. nur der feste Inhalt, statt eines Satzes mit Lücke.
 */
class MailTemplates
{
    /** @var array<string, array{label: string, subject: string, placeholders: list<string>, hint?: string}> */
    public const DEFINITIONS = [
        'account_invitation' => ['label' => 'Einladung mit neuem Konto (Passwort festlegen)', 'subject' => 'Einladung zu {schule}', 'placeholders' => ['schule', 'rolle']],
        'invitation' => ['label' => 'Einladung in eine Bootsschule (bestehendes Konto)', 'subject' => 'Einladung zu {schule}', 'placeholders' => ['schule']],
        'entitlement_expiring' => ['label' => 'Kurszugang läuft bald ab', 'subject' => 'Dein Kurszugang läuft am {datum} ab', 'placeholders' => ['schule', 'kurs', 'datum']],
        'group_entitlement_expiring' => ['label' => 'Zugang über eine Lerngruppe endet bald', 'subject' => 'Dein Zugang über die Lerngruppe endet am {datum}', 'placeholders' => ['schule', 'kurs', 'gruppe', 'datum'],
            'hint' => 'Bei aus WordPress übernommenen Gruppenzugängen ist der Gruppenname unbekannt: Betreff und Texte mit {gruppe} entfallen dann (Standard bzw. nur fester Inhalt).'],
        'coupon_redeemed' => ['label' => 'Code eingelöst / Kurs freigeschaltet', 'subject' => 'Dein Kurs ist freigeschaltet', 'placeholders' => ['kurs']],
        'willkommensbox_shipped' => ['label' => 'Willkommensbox versendet', 'subject' => 'Deine Willkommensbox ist unterwegs', 'placeholders' => []],
        'learning_reminder' => ['label' => 'Erinnerung ans Lernen (inaktive Teilnehmer)', 'subject' => 'Weiterlernen bei {schule}', 'placeholders' => ['schule', 'tage']],
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
        // Texte mit unbekanntem Platzhalter (null) auslassen.
        $unknown = array_map(fn (string $name) => '{'.$name.'}', array_keys(array_filter($vars, fn ($value) => $value === null)));
        foreach ($template as $part => $text) {
            if ($unknown !== [] && str_replace($unknown, '', $text) !== $text) {
                $template[$part] = '';
            }
        }

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
            // Review-Runde 12: dieselbe Zeichenauswahl wie EscapesMarkdown -- „e.V.“
            // oder „(Nord)“ erschienen sonst mit Backslashes im Text-Teil.
            $map['{'.$name.'}'] = $markdown ? preg_replace('/([\\\\`*_{}\[\]#|>~])/', '\\\\$1', $value) : $value;
        }

        return $map;
    }

    /** Nach dem Speichern im Superadmin neu laden. */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
