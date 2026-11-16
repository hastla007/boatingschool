<?php

namespace App\Support;

use App\Console\Commands\PrunePersonalData;
use App\Http\Controllers\Api\V1\AccountDeletionController;
use App\Http\Controllers\Api\V1\WebSessionController;
use App\Services\InvitationAcceptance;
use App\Services\LearningGroupService;

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
            'lerngruppen' => self::learningGroups(),
            'appeinstellungen' => self::appSettings(),
            'webansicht' => self::appWebSession(),
            'appkontoloeschung' => self::appAccountDeletion(),
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

    /**
     * Tägliche Datensicherung (config/backup.php). Die externe Kopie
     * verschlüsselt app:backup mit BACKUP_ENCRYPTION_PUBLIC_KEY vor dem
     * Hochladen (BackupEncryption); privacy.backups.encrypted folgt dem
     * standardmäßig, kann aber (z. B. für serverseitige Verschlüsselung)
     * mit PRIVACY_BACKUPS_ENCRYPTED gesetzt werden.
     */
    public static function backups(): string
    {
        $days = max(1, (int) config('backup.keep_days', 14));
        $text = "Damit keine Daten verloren gehen, sichern wir die Datenbank und hochgeladene Dateien täglich. Jede Sicherung wird nach {$days} Tagen automatisch gelöscht; "
            ."gelöschte Angaben verschwinden deshalb spätestens {$days} Tage nach der Löschung auch aus den Sicherungen.";
        if (config('backup.disk')) {
            $provider = trim((string) config('privacy.backups.provider'));
            $text .= ' Eine Kopie liegt bei einem Speicheranbieter'.($provider !== '' ? " ({$provider})" : '').', der in unserem Auftrag tätig ist';
            $text .= match (true) {
                filled(config('backup.encryption_public_key')) => '. Wir verschlüsseln diese Kopie, bevor sie unseren Server verlässt; der Speicheranbieter kann ihren Inhalt nicht lesen.',
                (bool) config('privacy.backups.encrypted') => ', verschlüsselt.',
                default => '.',
            };
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

    /**
     * Lerngruppen (Gruppenlizenzen, LearningGroupService): wer was sieht.
     * Verwalter (in der App) und Schule (Admin-Bereich) sehen Namen,
     * Einladungsadressen, Status und Beitrittsdatum, keine Lerndaten.
     */
    public static function learningGroups(): string
    {
        $days = LearningGroupService::INVITE_DAYS;

        return 'Deine Bootsschule kann Lerngruppen einrichten (Gruppenlizenzen, z. B. für einen Verein oder eine Firma): Eine verwaltende Person – selbst Teilnehmer der Bootsschule – vergibt in der Lern-App eine feste Zahl Plätze für bestimmte Kurse, per Beitrittscode oder per Einladung an eine E-Mail-Adresse. '
            .'Wer beitritt, erhält die Kurse der Gruppe bis zu deren Ende. '
            .'**Wer was sieht:** Die verwaltende Person sieht den Namen der Gruppe, die Namen der Mitglieder, die E-Mail-Adressen, an die sie eingeladen hat, ob eine Einladung offen, angenommen oder abgelaufen ist und seit wann jemand Mitglied ist. '
            .'Deine Bootsschule sieht dieselben Angaben in ihrem Verwaltungsbereich, dort mit der E-Mail-Adresse deines Kontos. '
            .'Deinen Lernstand, deine Antworten und deine Prüfungsergebnisse sieht die verwaltende Person nicht. Vor dem Beitritt siehst du den Namen der Gruppe und der verwaltenden Person. '
            .'Bei einer Einladung schicken wir im Namen der Bootsschule eine E-Mail mit dem Namen der verwaltenden Person und einem persönlichen Beitrittscode an die angegebene Adresse – auch, wenn es dazu noch kein Konto gibt; ob es eines gibt, erfährt die verwaltende Person nicht. '
            ."Eine nicht angenommene Einladung gilt {$days} Tage; danach entfernen wir die Adresse aus der Gruppe. "
            .'Entfernt dich die verwaltende Person oder die Bootsschule aus der Gruppe oder endet die Gruppe, endet der Gruppenzugang; dein Lernstand und eigene Kurszugänge bleiben. '
            .'Grundlage ist die Bereitstellung des Kurszugangs (Art. 6 Abs. 1 lit. b DSGVO), für Einladungen an Personen, die noch nicht Mitglied der Gruppe sind, das berechtigte Interesse an der Vergabe der Plätze (Art. 6 Abs. 1 lit. f DSGVO).';
    }

    /** App-Einstellungen je Konto (GET/PUT /api/v1/me/settings, api_user_setting). */
    public static function appSettings(): string
    {
        return '**Einstellungen:** Einstellungen, die du in der Lern-App vornimmst, speichern wir zu deinem Konto, damit sie auf allen deinen Geräten gleich sind. '
            .'Sie gelten für dein Konto, auch wenn du bei mehreren Bootsschulen lernst, sind Teil deines Datenexports und werden mit deinem Konto gelöscht (Art. 6 Abs. 1 lit. b DSGVO).';
    }

    /** Einmal-Anmeldung der Webansicht aus der App (WebSessionController); abschaltbar. */
    public static function appWebSession(): string
    {
        if (! config('mobile_api.web_session_enabled', true)) {
            return '';
        }
        $seconds = WebSessionController::TTL_SECONDS;

        return '**Webansicht:** Öffnest du aus der Lern-App eine Seite deiner Bootsschule im Web (z. B. Kurse oder Tipps), meldet die App dich dort mit einem Einmal-Code an, damit du dein Passwort nicht erneut eingeben musst. '
            ."Der Code gilt {$seconds} Sekunden und nur einmal; wir speichern ihn nur als Hashwert zusammen mit deinem Konto, dem Gerät und der aufgerufenen Seite und löschen ihn nach einem Tag. "
            .'Profil und Verwaltung lassen sich so nicht öffnen (Art. 6 Abs. 1 lit. b DSGVO).';
    }

    /** Kontolöschung aus der App mit Bestätigung per Mail (AccountDeletionController, AppAccountDeletion). */
    public static function appAccountDeletion(): string
    {
        $days = AccountDeletionController::CONFIRMATION_DAYS;

        return '**Konto löschen in der App:** Beantragst du in der Lern-App die Löschung deines Kontos, schicken wir dir eine E-Mail mit einem Bestätigungslink. '
            ."Erst mit der Bestätigung (in der App oder auf einer Webseite) löschen wir deine Mitgliedschaft und alle Lerndaten bei dieser Bootsschule sofort. Bist du bei keiner anderen Bootsschule angemeldet, löschen wir auch dein Konto. Der Link gilt {$days} Tage; ein nicht bestätigter Antrag wird danach gelöscht. "
            .'Im Änderungsprotokoll der Bootsschule bleibt nur ein Eintrag ohne Angaben zu deiner Person, dass ein Konto gelöscht wurde. Grundlage ist die Erfüllung deines Rechts auf Löschung (Art. 6 Abs. 1 lit. c i. V. m. Art. 17 DSGVO).';
    }

    /** Speicherdauer je Datenart, aus den eingestellten Löschfristen. */
    public static function retention(): string
    {
        $items = [];
        $inactive = config('data_retention.inactive_learners.months');
        $warning = (int) config('data_retention.inactive_learners.warning_days', 30);
        $items[] = '**Konto und Lernstand:** solange dein Konto besteht. Du kannst dein Konto jederzeit im Profil oder in der Lern-App löschen.'
            .($inactive ? " Meldest du dich {$inactive} Monate lang nicht an, lernst nicht und hast keinen aktiven Kurszugang, löschen wir dein Konto automatisch; {$warning} Tage vorher bekommst du eine E-Mail." : '');
        if (config('data_retention.attempt.enabled')) {
            $items[] = '**Einzelne Antworten im Fragentrainer:** '.(int) config('data_retention.attempt.months').' Monate, danach bleibt nur der zusammengefasste Lernstand.';
        }
        if (config('data_retention.unclaimed_accounts.enabled')) {
            $items[] = '**Nie angenommene Einladungen:** '.(int) config('data_retention.unclaimed_accounts.days').' Tage.';
        }
        if ($invitationDays = PrunePersonalData::tenantInvitationDays()) {
            $items[] = '**Einladungen einer Bootsschule, die du nicht annimmst:** '.$invitationDays.' Tage (gültig sind sie '.InvitationAcceptance::VALID_DAYS.' Tage).';
        }
        if (config('data_retention.sessions.enabled')) {
            $items[] = '**Anmeldesitzungen im Browser:** '.(int) config('data_retention.sessions.days').' Tage nach der letzten Nutzung.';
        }
        if ($praxisMonths = config('data_retention.praxis_submissions.months')) {
            $items[] = '**Angaben aus der Praxis-Anmeldung:** '.(int) $praxisMonths.' Monate nach dem letzten Termin, danach bleiben nur Anmeldung und Termine ohne deine Angaben.';
        }
        if ($boxDays = config('data_retention.willkommensbox_addresses.days')) {
            $items[] = '**Lieferadresse der Willkommensbox:** '.(int) $boxDays.' Tage nach dem Versand.';
        }
        $exportMinutes = (int) config('mobile_api.export_ttl_minutes', 60);
        $items[] = '**Lern-App:** abgemeldete oder abgelaufene Geräte 30 Tage, ein Datenexport aus der App '.($exportMinutes === 60 ? 'eine Stunde' : "{$exportMinutes} Minuten")
            .', ein nicht bestätigter Antrag auf Kontolöschung '.AccountDeletionController::CONFIRMATION_DAYS.' Tage'
            .(config('mobile_api.web_session_enabled', true) ? ', Einmal-Codes für die Webansicht einen Tag' : '')
            .'; App-Einstellungen, solange dein Konto besteht.';
        $items[] = '**Einladungen in eine Lerngruppe:** '.LearningGroupService::INVITE_DAYS.' Tage, wenn sie nicht angenommen werden; die Mitgliedschaft in einer Lerngruppe, solange du bei der Bootsschule angemeldet bist.';
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
