<?php

namespace App\Support;

/**
 * Bausteine der Datenschutzerklärung, die von der Konfiguration abhängen
 * (Platzhalter in LegalPages::DEFAULT_DATENSCHUTZ): Ein nicht eingerichteter
 * Dienst ergibt einen leeren Wert -- LegalPages::fill() lässt dann Zeile und
 * Überschrift weg. Die Fristen entsprechen docs/recht/loeschkonzept.md.
 */
class PrivacyNotices
{
    /** @return array<string, string> */
    public static function values(): array
    {
        return [
            'videos' => self::videos(),
            'fehleranalyse' => self::errorTracking(),
            'datensicherung' => self::backups(),
            'shop' => self::shop(),
            'speicherdauer' => self::retention(),
        ];
    }

    /** Videohoster (PRIVACY_VIDEO_PROVIDER); ohne Namen kein Abschnitt. */
    public static function videos(): string
    {
        $name = trim((string) config('privacy.video_provider.name'));
        if ($name === '') {
            return '';
        }
        $country = trim((string) config('privacy.video_provider.country'));
        $text = 'Die Lernvideos stellen wir über '.$name.($country !== '' ? " ({$country})" : '').' bereit. '
            .'Wenn du ein Video abspielst, lädt dein Browser bzw. die Lern-App es direkt von den Servern des Anbieters; '
            .'dabei werden deine IP-Adresse, technische Angaben zu Browser bzw. Gerät und der Zeitpunkt des Abrufs übermittelt. '
            .'Der Anbieter ist als Auftragsverarbeiter an unsere Weisungen gebunden. Grundlage ist die Bereitstellung deines Kurses (Art. 6 Abs. 1 lit. b DSGVO).';
        if ($country !== '' && ! in_array($country, (array) config('privacy.eu_eea_countries', []), true)) {
            $text .= ' Der Anbieter hat seinen Sitz außerhalb der EU bzw. des EWR; die Übermittlung stützt sich auf '
                .trim((string) config('privacy.video_provider.transfer_basis')).'.';
        }
        $url = trim((string) config('privacy.video_provider.privacy_url'));

        return $url !== '' ? $text.' Mehr dazu: '.$url : $text;
    }

    /** Fehlertracking (Sentry) nur mit gesetzter DSN. */
    public static function errorTracking(): string
    {
        $dsn = (string) config('sentry.dsn');
        if (trim($dsn) === '') {
            return '';
        }
        $host = strtolower((string) parse_url($dsn, PHP_URL_HOST));
        $saas = $host === 'sentry.io' || str_ends_with($host, '.sentry.io');
        $name = $saas ? trim((string) config('privacy.error_tracking.name')) : 'eine von uns bzw. in unserem Auftrag betriebene Sentry-Instanz';
        $location = match (true) {
            str_ends_with($host, '.de.sentry.io') => ' Die Fehlerberichte werden in der EU (Frankfurt am Main) gespeichert.',
            $saas => ' Dabei kann eine Übermittlung in die USA stattfinden; sie stützt sich auf das EU-US Data Privacy Framework bzw. EU-Standardvertragsklauseln (Art. 45, 46 DSGVO).',
            default => '',
        };
        $days = (int) config('privacy.error_tracking.retention_days', 90);

        return 'Tritt in der Plattform ein technischer Fehler auf, senden wir einen Fehlerbericht an '.$name.'. '
            .'Er enthält technische Angaben wie die aufgerufene Adresse (ohne Zugangsschlüssel), Zeitpunkt, Fehlermeldung und Programmversion, '
            .'aber weder deine IP-Adresse noch Formularinhalte, Passwörter oder Antworten; E-Mail-Adressen entfernen wir vorher. '
            .'Zweck ist die schnelle Behebung von Fehlern (berechtigtes Interesse, Art. 6 Abs. 1 lit. f DSGVO).'
            .$location." Fehlerberichte werden nach {$days} Tagen gelöscht.";
    }

    /** Tägliche Datensicherung (config/backup.php). */
    public static function backups(): string
    {
        $days = max(1, (int) config('backup.keep_days', 14));
        $text = "Damit keine Daten verloren gehen, sichern wir die Datenbank und hochgeladene Dateien täglich. Jede Sicherung wird nach {$days} Tagen automatisch gelöscht; "
            ."gelöschte Angaben verschwinden deshalb spätestens {$days} Tage nach der Löschung auch aus den Sicherungen.";
        if (config('backup.disk')) {
            $provider = trim((string) config('privacy.backups.provider'));
            $text .= ' Eine Kopie liegt bei einem Speicheranbieter'.($provider !== '' ? " ({$provider})" : '').', der in unserem Auftrag tätig ist';
            $text .= config('privacy.backups.encrypted') ? ', verschlüsselt.' : '.';
        } elseif (config('privacy.backups.encrypted')) {
            $text .= ' Die Sicherungen werden verschlüsselt gespeichert.';
        }

        return $text;
    }

    /** B2B-Shop für Zugangscodes: nur mit eingerichteter Shop-Anbindung. */
    public static function shop(): string
    {
        if (array_filter((array) config('commerce.keys')) === [] && ! config('commerce.shop_url')) {
            return '';
        }

        return 'Bootsschulen kaufen Zugangscodes in einem Online-Shop des Plattformbetreibers. Das betrifft nur Inhaber und Mitarbeitende der Bootsschule, nicht Lernende. '
            .'Für das Shopkonto werden Name, E-Mail-Adresse, Firma, Rechnungsdaten, USt-IdNr. und die Bestellungen verarbeitet; verantwortlich dafür ist der Betreiber. '
            .'Die Plattform erhält vom Shop nur die Angaben, die sie zur Zuordnung des Shopkontos zur Bootsschule und zur Lieferung der Codes braucht; '
            .'die Codes werden ausschließlich im Codebestand der Bootsschule angelegt. Grundlage sind der Kaufvertrag und gesetzliche Aufbewahrungspflichten (Art. 6 Abs. 1 lit. b und c DSGVO).';
    }

    /** Speicherdauer je Datenart, aus den eingestellten Löschfristen. */
    public static function retention(): string
    {
        $items = [];
        $inactive = config('data_retention.inactive_learners.months');
        $warning = (int) config('data_retention.inactive_learners.warning_days', 30);
        $items[] = '**Konto und Lernstand:** solange dein Konto besteht. Du kannst dein Konto jederzeit im Profil löschen.'
            .($inactive ? " Meldest du dich {$inactive} Monate lang nicht an, lernst nicht und hast keinen aktiven Kurszugang, löschen wir dein Konto automatisch; {$warning} Tage vorher bekommst du eine E-Mail." : '');
        if (config('data_retention.attempt.enabled')) {
            $items[] = '**Einzelne Antworten im Fragentrainer:** '.(int) config('data_retention.attempt.months').' Monate, danach bleibt nur der zusammengefasste Lernstand.';
        }
        if (config('data_retention.unclaimed_accounts.enabled')) {
            $items[] = '**Nie angenommene Einladungen:** '.(int) config('data_retention.unclaimed_accounts.days').' Tage.';
        }
        if (config('data_retention.sessions.enabled')) {
            $items[] = '**Anmeldesitzungen im Browser:** '.(int) config('data_retention.sessions.days').' Tage nach der letzten Nutzung.';
        }
        $exportMinutes = (int) config('mobile_api.export_ttl_minutes', 60);
        $items[] = '**Lern-App:** abgemeldete oder abgelaufene Geräte 30 Tage, ein Datenexport aus der App '.($exportMinutes === 60 ? 'eine Stunde' : "{$exportMinutes} Minuten").'.';
        $items[] = '**Links zum Setzen des Passworts:** '.(int) config('auth.passwords.users.expire', 60).' Minuten.';
        $items[] = '**Server-Protokolle:** '.(int) config('logging.channels.daily.days', 14).' Tage.';
        $items[] = config('data_retention.audit_log.enabled')
            ? '**Änderungsprotokoll der Bootsschule:** '.(int) config('data_retention.audit_log.months').' Monate.'
            : '**Änderungsprotokoll der Bootsschule:** bis zur Löschung deines Kontos; danach bleiben nur Aktion und Zeitpunkt ohne Angaben zu deiner Person.';
        $items[] = '**Datensicherungen:** '.max(1, (int) config('backup.keep_days', 14)).' Tage.';
        if (trim((string) config('sentry.dsn')) !== '') {
            $items[] = '**Fehlerberichte:** '.(int) config('privacy.error_tracking.retention_days', 90).' Tage.';
        }

        return '- '.implode("\n- ", $items)."\n\nGesetzliche Aufbewahrungspflichten (z. B. für Rechnungen) bleiben unberührt.";
    }
}
