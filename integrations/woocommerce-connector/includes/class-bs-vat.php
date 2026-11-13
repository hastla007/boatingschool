<?php

/**
 * USt-IdNr.-Prüfung, ohne WordPress-Abhängigkeit (testbar): Aufbereitung der
 * Eingabe, Formatprüfung je EU-Land und Auswertung der Antwort des
 * VIES-REST-Dienstes der EU-Kommission
 * (POST https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number).
 *
 * Den Aufruf selbst, das Speichern und die Wiederholung macht BS_Vat_Check.
 */
defined('ABSPATH') || defined('BS_CONNECTOR_TESTING') || exit;

final class BS_Vat
{
    const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

    /** VIES bestätigt die Nummer. */
    const VALID = 'valid';

    /** Falsches Format oder laut VIES ungültig: Registrierung/Kasse lehnt ab. */
    const INVALID = 'invalid';

    /** VIES (oder der Dienst des Mitgliedstaats) nicht erreichbar: angenommen, wird später erneut geprüft. */
    const UNCHECKED = 'unchecked';

    /** Kein EU-Land (z. B. CH, GB): VIES ist nicht zuständig, nicht geprüft. */
    const NOT_APPLICABLE = 'not_applicable';

    /**
     * Formate der Nummer ohne Länderpräfix (VIES-Ländercodes; Griechenland = EL,
     * Nordirland = XI). Quelle: Merkblatt der EU-Kommission zu VIES.
     */
    const FORMATS = [
        'AT' => '/^U\d{8}$/',
        'BE' => '/^[01]\d{9}$/',
        'BG' => '/^\d{9,10}$/',
        'CY' => '/^\d{8}[A-Z]$/',
        'CZ' => '/^\d{8,10}$/',
        'DE' => '/^\d{9}$/',
        'DK' => '/^\d{8}$/',
        'EE' => '/^\d{9}$/',
        'EL' => '/^\d{9}$/',
        'ES' => '/^[A-Z0-9]\d{7}[A-Z0-9]$/',
        'FI' => '/^\d{8}$/',
        'FR' => '/^[A-HJ-NP-Z0-9]{2}\d{9}$/',
        'HR' => '/^\d{11}$/',
        'HU' => '/^\d{8}$/',
        'IE' => '/^(\d{7}[A-W][A-IW]?|\d[A-Z+*]\d{5}[A-W])$/',
        'IT' => '/^\d{11}$/',
        'LT' => '/^(\d{9}|\d{12})$/',
        'LU' => '/^\d{8}$/',
        'LV' => '/^\d{11}$/',
        'MT' => '/^\d{8}$/',
        'NL' => '/^\d{9}B\d{2}$/',
        'PL' => '/^\d{10}$/',
        'PT' => '/^\d{9}$/',
        'RO' => '/^[1-9]\d{1,9}$/',
        'SE' => '/^\d{10}01$/',
        'SI' => '/^\d{8}$/',
        'SK' => '/^\d{10}$/',
        'XI' => '/^(\d{9}|\d{12}|GD[0-4]\d{2}|HA[5-9]\d{2})$/',
    ];

    /** Fehlercodes von VIES, bei denen der Dienst nicht antworten konnte: später erneut prüfen. */
    const RETRYABLE_ERRORS = [
        'MS_UNAVAILABLE',
        'SERVICE_UNAVAILABLE',
        'TIMEOUT',
        'GLOBAL_MAX_CONCURRENT_REQ',
        'GLOBAL_MAX_CONCURRENT_REQ_TIME',
        'MS_MAX_CONCURRENT_REQ',
        'MS_MAX_CONCURRENT_REQ_TIME',
        'IP_BLOCKED',
    ];

    /** Fehlercodes, die die Nummer selbst betreffen: wie „ungültig“. */
    const INVALID_ERRORS = ['INVALID_INPUT', 'VAT_BLOCKED'];

    /** Wartezeiten bis zur nächsten Prüfung, solange VIES nicht erreichbar ist (Minuten). */
    const RETRY_MINUTES = [15, 60, 240, 720, 1440];

    /** ISO-Ländercode (Rechnungsland) → VIES-Ländercode. */
    public static function vies_country(string $iso): string
    {
        $iso = strtoupper(trim($iso));

        return $iso === 'GR' ? 'EL' : $iso;
    }

    public static function is_eu(string $vies_country): bool
    {
        return isset(self::FORMATS[$vies_country]);
    }

    /**
     * Eingabe aufbereiten: Leerzeichen, Punkte, Bindestriche und Schrägstriche
     * entfernen, Großbuchstaben. Ein vorangestellter EU-Ländercode (auch „GR“)
     * hat Vorrang vor dem Rechnungsland; steht die Kennung des Rechnungslands
     * vorn (z. B. CHE-123.456.789 bei CH), wird sie nicht doppelt gesetzt.
     *
     * @return array{country: string, number: string, vat_id: string}|null null bei leerer Eingabe.
     */
    public static function normalize(string $vat_id, string $billing_country = ''): ?array
    {
        $clean = strtoupper((string) preg_replace('/[\s.\-\/_,]+/u', '', $vat_id));
        if ($clean === '') {
            return null;
        }
        $country = self::vies_country($billing_country);
        $number = $clean;
        if (preg_match('/^([A-Z]{2})(.+)$/', $clean, $m)) {
            $prefix = self::vies_country($m[1]);
            // EU-Präfix, oder die Länderkennung des (Nicht-EU-)Rechnungslands (z. B. CHE-…).
            if (self::is_eu($prefix) || ($country !== '' && $prefix === $country)) {
                $country = $prefix;
                $number = $m[2];
            }
        }

        return ['country' => $country, 'number' => $number, 'vat_id' => $country.$number];
    }

    /** Stimmt das Format für das Land? (Nur EU-Länder; sonst false.) */
    public static function format_valid(string $country, string $number): bool
    {
        return self::is_eu($country) && preg_match(self::FORMATS[$country], $number) === 1;
    }

    /**
     * Vorprüfung ohne VIES. Ergebnis-Status null = an VIES senden; sonst steht
     * das Ergebnis schon fest (Format falsch oder kein EU-Land).
     *
     * @return array{status: string|null, country: string, number: string, vat_id: string, error: string|null}|null
     */
    public static function precheck(string $vat_id, string $billing_country = ''): ?array
    {
        $normalized = self::normalize($vat_id, $billing_country);
        if ($normalized === null) {
            return null;
        }
        if (! self::is_eu($normalized['country'])) {
            return $normalized + ['status' => self::NOT_APPLICABLE, 'error' => null];
        }
        if (! self::format_valid($normalized['country'], $normalized['number'])) {
            return $normalized + ['status' => self::INVALID, 'error' => 'FORMAT'];
        }

        return $normalized + ['status' => null, 'error' => null];
    }

    /**
     * JSON-Body der Anfrage. Mit der eigenen USt-IdNr. (requester) liefert VIES
     * eine Abfragenummer (requestIdentifier) als Nachweis.
     */
    public static function request_body(string $country, string $number, string $requester = ''): array
    {
        $body = ['countryCode' => $country, 'vatNumber' => $number];
        $own = self::normalize($requester);
        if ($own !== null && self::is_eu($own['country'])) {
            $body['requesterMemberStateCode'] = $own['country'];
            $body['requesterNumber'] = $own['number'];
        }

        return $body;
    }

    /**
     * Antwort von VIES auswerten.
     *
     * @param  int|null  $http_status  null = keine Antwort (Netzfehler, Zeitüberschreitung).
     * @param  array|null  $body  Dekodierter JSON-Body.
     * @return array{status: string, retry: bool, error: string|null, request_identifier: string, request_date: string, name: string}
     */
    public static function map_response(?int $http_status, ?array $body): array
    {
        $result = [
            'status' => self::UNCHECKED,
            'retry' => true,
            'error' => null,
            'request_identifier' => '',
            'request_date' => '',
            'name' => '',
        ];
        if ($http_status === null) {
            $result['error'] = 'NO_RESPONSE';

            return $result;
        }

        // Fehler kommen als errorWrappers (400/500) oder als userError (200).
        $error = null;
        if (is_array($body) && isset($body['errorWrappers'][0]['error'])) {
            $error = strtoupper((string) $body['errorWrappers'][0]['error']);
        } elseif (is_array($body) && isset($body['userError']) && ! in_array(strtoupper((string) $body['userError']), ['VALID', 'INVALID', ''], true)) {
            $error = strtoupper((string) $body['userError']);
        }

        if ($error !== null) {
            $result['error'] = $error;
            if (in_array($error, self::INVALID_ERRORS, true)) {
                $result['status'] = self::INVALID;
                $result['retry'] = false;
            }

            return $result;
        }
        if ($http_status < 200 || $http_status >= 300 || ! is_array($body) || ! array_key_exists('valid', $body)) {
            $result['error'] = 'HTTP_'.$http_status;

            return $result;
        }

        $name = trim((string) ($body['name'] ?? ''));

        return [
            'status' => $body['valid'] === true || $body['valid'] === 'true' ? self::VALID : self::INVALID,
            'retry' => false,
            'error' => null,
            'request_identifier' => (string) ($body['requestIdentifier'] ?? ''),
            'request_date' => (string) ($body['requestDate'] ?? ''),
            'name' => $name === '---' ? '' : $name,
        ];
    }

    /** Minuten bis zur nächsten Prüfung nach dem Versuch $attempt (ab 1); null = aufgeben. */
    public static function retry_delay(int $attempt): ?int
    {
        return self::RETRY_MINUTES[$attempt - 1] ?? null;
    }
}
