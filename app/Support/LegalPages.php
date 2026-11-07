<?php

namespace App\Support;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantBranding;
use Illuminate\Support\Str;

/**
 * Impressum und Datenschutzerklärung je Bootsschule: eine zentrale Vorlage
 * (Superadmin, Website-Einstellungen) mit Platzhaltern wie {schule} oder
 * {anschrift}, in die die Stammdaten der Schule aus dem Branding eingesetzt
 * werden. Ohne eigene Vorlage gilt der mitgelieferte Entwurf -- er ist ein
 * Ausgangspunkt und ersetzt keine rechtliche Prüfung.
 *
 * Eine Zeile, deren Platzhalter alle leer sind (z. B. „Registereintrag:
 * {register}“ ohne Registereintrag), entfällt.
 */
class LegalPages
{
    public const KINDS = ['impressum' => 'Impressum', 'datenschutz' => 'Datenschutzerklärung'];

    /** Platzhalter und ihre Bedeutung (für die Hilfe im Superadmin). */
    public const PLACEHOLDERS = [
        'schule' => 'Name der Bootsschule (Firmenname aus dem Branding)',
        'anschrift' => 'Straße, PLZ, Ort, Land',
        'vertreten' => 'Vertretungsberechtigte Person (sonst die Ansprechperson)',
        'telefon' => 'Telefonnummer',
        'email' => 'Support-E-Mail',
        'website' => 'Website',
        'register' => 'Registergericht und Registernummer',
        'ustid' => 'Umsatzsteuer-Identifikationsnummer',
        'plattform' => 'Name der Plattform',
        'betreiber' => 'Angaben zum Betreiber der Plattform (Website-Einstellungen)',
    ];

    public const DEFAULT_IMPRESSUM = <<<'MD'
## Angaben gemäß § 5 Digitale-Dienste-Gesetz (DDG)

{schule}
{anschrift}

Vertreten durch: {vertreten}

## Kontakt

Telefon: {telefon}
E-Mail: {email}
Website: {website}

## Register und Umsatzsteuer

Registereintrag: {register}
Umsatzsteuer-Identifikationsnummer gemäß § 27a Umsatzsteuergesetz: {ustid}

## Verbraucherstreitbeilegung

Wir sind nicht bereit und nicht verpflichtet, an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen.

## Technischer Betreiber der Lernplattform

{betreiber}
MD;

    public const DEFAULT_DATENSCHUTZ = <<<'MD'
## 1. Verantwortlicher

Verantwortlich für die Verarbeitung deiner personenbezogenen Daten in dieser Lernplattform ist:

{schule}
{anschrift}
E-Mail: {email}
Telefon: {telefon}

## 2. Betreiber der Plattform

Die Lernplattform „{plattform}“ wird technisch betrieben von:

{betreiber}

Der Betreiber verarbeitet die Daten im Auftrag der Bootsschule (Auftragsverarbeitung nach Art. 28 DSGVO).

## 3. Welche Daten wir verarbeiten

- **Konto:** Name, E-Mail-Adresse, Passwort (verschlüsselt gespeichert), freigeschaltete Kurse.
- **Lernen:** Antworten, Lernfortschritt, Ergebnisse der Prüfungssimulation, Übungen im Praxistrainer und in der Funk-Gerätesimulation.
- **Praxisprüfung und Willkommensbox:** die Angaben im Anmeldeformular, Prüfungstermine sowie die Lieferadresse, wenn du eine Willkommensbox bestellst.
- **Technik:** Server-Protokolle (z. B. IP-Adresse, Zeitpunkt, aufgerufene Seite) für den sicheren Betrieb.

## 4. Zwecke und Rechtsgrundlagen

- Bereitstellung der Kurse und deines Lernfortschritts, Organisation der Praxisprüfung und Versand der Willkommensbox: Vertrag (Art. 6 Abs. 1 lit. b DSGVO).
- Sicherer Betrieb und Schutz vor Missbrauch: berechtigtes Interesse (Art. 6 Abs. 1 lit. f DSGVO).
- Aufbewahrungspflichten: rechtliche Verpflichtung (Art. 6 Abs. 1 lit. c DSGVO).

## 5. Cookies und Schriften

Wir verwenden nur technisch notwendige Cookies (Anmeldung und Sicherheit), keine Werbe- oder Analyse-Cookies. Schriften werden von unserem eigenen Server geladen, nicht von Drittanbietern.

## 6. Spracherkennung in der Funk-Gerätesimulation

Wenn du einen Funkspruch einsprichst, nutzt die Gerätesimulation die Spracherkennung deines Browsers. Je nach Browser wird die Aufnahme dafür an den Anbieter des Browsers übertragen (z. B. Google bei Chrome). Das geschieht nur, solange du die Sprechtaste hältst. Du kannst den Funkspruch stattdessen auch eintippen.

## 7. Karten (OpenStreetMap)

Bei der Anmeldung zur Praxisprüfung zeigt eine Karte die Prüfungsorte. Die Kartenkacheln lädt dein Browser von den Servern der OpenStreetMap Foundation (St John's Innovation Centre, Cowley Road, Cambridge, CB4 0WS, Vereinigtes Königreich). Dabei werden deine IP-Adresse und technische Angaben deines Browsers an OpenStreetMap übermittelt; Grundlage ist unser berechtigtes Interesse an einer übersichtlichen Ortsauswahl (Art. 6 Abs. 1 lit. f DSGVO). Für das Vereinigte Königreich besteht ein Angemessenheitsbeschluss der EU-Kommission. Mehr dazu: https://osmfoundation.org/wiki/Privacy_Policy. Den Prüfungsort kannst du auch ohne Karte aus der Liste wählen.

## 8. Empfänger

Deine Daten sehen die Bootsschule und der Betreiber der Plattform. Für Hosting und E-Mail-Versand setzen wir Dienstleister ein, die nach Art. 28 DSGVO an unsere Weisungen gebunden sind.

## 9. Speicherdauer

Wir speichern deine Daten, solange dein Konto besteht. Danach löschen wir sie, soweit keine gesetzlichen Aufbewahrungspflichten entgegenstehen.

## 10. Deine Rechte

Du hast das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit und Widerspruch (Art. 15 bis 21 DSGVO). Deine Daten kannst du im Profil selbst exportieren, dein Konto dort auch löschen. Außerdem kannst du dich bei einer Datenschutz-Aufsichtsbehörde beschweren.

## 11. Kontakt

Fragen zum Datenschutz richtest du an: {email}
MD;

    /**
     * Datenschutzhinweis der zentralen Plattformseite (ohne Schule). Die
     * Vorlage der Schulen passt hier nicht: dort ist die Bootsschule
     * Verantwortliche und der Betreiber Auftragsverarbeiter.
     */
    private const PLATFORM_DATENSCHUTZ = <<<'MD'
## Verantwortlich für diese Seite

{betreiber}

## Lernen bei deiner Bootsschule

Die Lernplattform nutzt du über deine Bootsschule. Verantwortlich für deine Daten beim Lernen ist deine Bootsschule; ihre Datenschutzerklärung findest du unten auf jeder Seite deiner Bootsschule. Wir betreiben die Plattform in ihrem Auftrag.

## Diese Seite

Beim Aufruf dieser Seite werden technisch notwendige Daten (IP-Adresse, Zeitpunkt, aufgerufene Adresse) kurzzeitig in Server-Protokollen gespeichert, um den Betrieb und die Sicherheit zu gewährleisten. Es werden nur technisch notwendige Cookies gesetzt; es findet kein Tracking statt.

## Kontakt

Fragen zum Datenschutz richtest du an: {email}
MD;

    /** Inhalt der Seite als HTML (Markdown aus der Vorlage, Platzhalter eingesetzt). */
    public static function html(string $kind, ?Tenant $tenant): string
    {
        $settings = PlatformSetting::query()->first();

        // Zentrale Domain ohne Schule: Angaben des Plattformbetreibers.
        if ($tenant === null) {
            $operator = trim((string) ($settings?->operator_info ?? ''));
            $values = ['schule' => $operator !== '' ? $operator : PlatformSetting::siteName(), 'email' => (string) ($settings?->support_email ?? '')]
                + self::values(null, null, $settings);
            $markdown = $kind === 'impressum'
                ? self::fill("## Anbieter\n\n{betreiber}\n\n## Kontakt\n\nE-Mail: {email}", $values)
                : self::fill(self::PLATFORM_DATENSCHUTZ, $values);
            if (trim($markdown) === '') {
                $markdown = 'Die Angaben des Betreibers werden noch ergänzt.';
            }

            return Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
        }

        $template = $kind === 'impressum'
            ? ($settings?->imprint_template ?: self::DEFAULT_IMPRESSUM)
            : ($settings?->privacy_template ?: self::DEFAULT_DATENSCHUTZ);

        $markdown = self::fill($template, self::values($tenant?->branding, $tenant, $settings));

        // Kein HTML aus der Vorlage oder den Stammdaten durchlassen.
        return Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    /** @return array<string, string> */
    public static function values(?TenantBranding $branding, ?Tenant $tenant, ?PlatformSetting $settings): array
    {
        $contact = trim(($branding?->contact_first_name ?? '').' '.($branding?->contact_last_name ?? ''));
        $city = trim(($branding?->postal_code ?? '').' '.($branding?->city ?? ''));
        $address = array_filter([$branding?->street, $city, $branding?->country]);

        return [
            'schule' => $branding?->legal_name ?: ($tenant?->name ?? ''),
            'anschrift' => implode("\n", $address),
            'vertreten' => $branding?->representative ?: $contact,
            'telefon' => (string) ($branding?->phone ?? ''),
            'email' => (string) ($branding?->support_email ?? ''),
            'website' => (string) ($branding?->website ?? ''),
            'register' => (string) ($branding?->register_entry ?? ''),
            'ustid' => (string) ($branding?->vat_id ?? ''),
            'plattform' => PlatformSetting::siteName(),
            'betreiber' => (string) ($settings?->operator_info ?? ''),
        ];
    }

    /** @param array<string, string> $values */
    public static function fill(string $template, array $values): string
    {
        $lines = [];
        $source = preg_split('/\R/', $template);
        $isEmpty = function (string $line) use ($values): bool {
            preg_match_all('/\{([a-z]+)\}/', $line, $m);
            $known = array_values(array_intersect($m[1], array_keys($values)));

            return $known !== [] && collect($known)->every(fn ($key) => trim($values[$key]) === '');
        };
        foreach ($source as $i => $line) {
            if ($isEmpty($line)) {
                continue;
            }
            // Einleitung mit Doppelpunkt, deren Angabe in der nächsten Zeile
            // leer ist („… wird technisch betrieben von:“), entfällt mit.
            $nextIndex = $i + 1;
            while (isset($source[$nextIndex]) && trim($source[$nextIndex]) === '') {
                $nextIndex++;
            }
            if (str_ends_with(rtrim($line), ':') && isset($source[$nextIndex]) && $isEmpty($source[$nextIndex])) {
                continue;
            }
            // Mehrzeilige Werte (Anschrift, Betreiber) als harte Zeilenumbrüche.
            $lines[] = preg_replace_callback('/\{([a-z]+)\}/', fn ($match) => array_key_exists($match[1], $values)
                ? str_replace("\n", "  \n", trim($values[$match[1]]))
                : $match[0], $line);
        }

        // Überschriften, unter denen nichts mehr steht (z. B. „Register und
        // Umsatzsteuer“ ohne beide Angaben), entfallen ebenfalls.
        $kept = [];
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, '#')) {
                $rest = array_slice($lines, $i + 1);
                $next = collect($rest)->search(fn ($l) => str_starts_with($l, '#'));
                $body = $next === false ? $rest : array_slice($rest, 0, $next);
                if (trim(implode('', $body)) === '') {
                    continue;
                }
            }
            $kept[] = $line;
        }

        // Einfache Zeilenumbrüche innerhalb eines Absatzes bleiben erhalten.
        return preg_replace('/(?<=\S)\n(?=[^\n#\-])/', "  \n", implode("\n", $kept));
    }

    /**
     * Angaben, die für ein vollständiges Impressum im Branding fehlen.
     *
     * @return list<string>
     */
    public static function missingImprintFields(TenantBranding $branding): array
    {
        return array_keys(array_filter([
            'Firmenname' => ! $branding->legal_name,
            'Anschrift (Straße, PLZ, Ort)' => ! $branding->street || ! $branding->postal_code || ! $branding->city,
            'Vertretungsberechtigte Person oder Ansprechperson' => ! $branding->representative && ! trim(($branding->contact_first_name ?? '').($branding->contact_last_name ?? '')),
            'Support-E-Mail' => ! $branding->support_email,
        ]));
    }
}
