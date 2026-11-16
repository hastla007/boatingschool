<?php

/**
 * Connector `boatingschool_form` für Form Captain (Contract v1, docs/CONNECTORS.md von Form Captain).
 *
 * Aktion `boatingschool_form.send_lead`: übergibt eine Einsendung als Lead an den Lead-Eingang der Plattform
 * (POST /api/integrations/v1/form-captain/leads, Vertrag form-captain-lead/1). Läuft nur im Worker von Form Captain.
 * Idempotenz über den Idempotency-Key des Jobs (Website + Entry + Aktion + Instanz): eine Wiederholung – auch nach
 * einem Timeout nach erfolgreichem Schreiben oder einer manuellen Wiederholung in Form Captain – liefert dieselbe
 * lead_id und legt keinen zweiten Lead an.
 */

declare(strict_types=1);

namespace BoatingschoolFormConnector;

use FormCaptain\Integrations\ConnectorInterface;

defined('ABSPATH') || exit;

final class Connector implements ConnectorInterface
{
    public const KEY = 'boatingschool_form';

    public const ACTION = 'boatingschool_form.send_lead';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('BoatingSchool-Leads', 'boatingschool-form-connector');
    }

    public function version(): string
    {
        return BOATINGSCHOOL_FORM_VERSION;
    }

    public function contract(): string
    {
        return '1.0';
    }

    public function capabilities(): array
    {
        return [self::ACTION];
    }

    public function requirements(): array
    {
        if (! Settings::is_configured()) {
            return ['ok' => false, 'messages' => [__('Adresse der Plattform, Schlüssel-ID und Geheimnis fehlen (wp-config.php oder Einstellungen des Connectors).', 'boatingschool-form-connector')]];
        }

        return ['ok' => true, 'messages' => [sprintf(
            /* translators: %s: host of the platform */
            __('Leads gehen an %s (nur gemappte Felder, siehe Datenfluss in der Plugin-Beschreibung).', 'boatingschool-form-connector'),
            (string) parse_url(Settings::endpoint(), PHP_URL_HOST)
        )]];
    }

    public function settings_schema(): array
    {
        return [];
    }

    public function register_actions(): void
    {
        form_captain_register_action_v1(self::ACTION, [
            'label' => __('Als Lead an BoatingSchool senden', 'boatingschool-form-connector'),
            'version' => '1.0.0',
            'contract' => '^1.0',
            'connector' => self::KEY,
            'settings' => [
                ['key' => 'purpose', 'type' => 'select', 'label' => __('Anliegen', 'boatingschool-form-connector'), 'required' => true, 'default' => 'demo',
                    'options' => ['demo' => __('Demo', 'boatingschool-form-connector'), 'consultation' => __('Beratung', 'boatingschool-form-connector'), 'school_access_info' => __('Infos zum Schulzugang', 'boatingschool-form-connector')]],
                ['key' => 'consent_text_revision', 'type' => 'number', 'label' => __('Revision des Einwilligungstexts', 'boatingschool-form-connector'), 'required' => true, 'default' => '1'],
                ['key' => 'consent_text_hash', 'type' => 'text', 'label' => __('SHA-256 des Einwilligungstexts (optional)', 'boatingschool-form-connector')],
                ['key' => 'page_url', 'type' => 'url', 'label' => __('Seite des Formulars (nur erlaubte Hosts)', 'boatingschool-form-connector')],
            ],
            // Nur diese Felder, nie sensible (kein allow_sensitive).
            'mapping' => [
                ['key' => 'email', 'label' => __('E-Mail', 'boatingschool-form-connector'), 'types' => ['email'], 'required' => true],
                ['key' => 'consent', 'label' => __('Einwilligung', 'boatingschool-form-connector'), 'types' => ['consent', 'checkbox'], 'required' => true],
                ['key' => 'name', 'label' => __('Name', 'boatingschool-form-connector'), 'types' => ['text']],
                ['key' => 'phone', 'label' => __('Telefon', 'boatingschool-form-connector'), 'types' => ['tel', 'text']],
                ['key' => 'organisation', 'label' => __('Bootsschule / Organisation', 'boatingschool-form-connector'), 'types' => ['text']],
                ['key' => 'role', 'label' => __('Rolle', 'boatingschool-form-connector'), 'types' => ['text', 'select', 'radio']],
                ['key' => 'school_size', 'label' => __('Schulgröße (1-2, 3-10, 11-50, 50+)', 'boatingschool-form-connector'), 'types' => ['select', 'radio']],
                ['key' => 'message', 'label' => __('Nachricht', 'boatingschool-form-connector'), 'types' => ['textarea', 'text']],
            ],
            'events' => ['entry_created'],
        ], [$this, 'send_lead']);
    }

    /**
     * Callback der Aktion. Unbekannte Schlüssel im Job werden bewusst ignoriert (Vorwärtskompatibilität).
     *
     * @param  array<string,mixed>  $job
     * @return array{status:string,code:string,message:string,remote_id?:string}
     */
    public function send_lead(array $job): array
    {
        if (! empty($job['test_mode'])) {
            // Testmodus: nur Erreichbarkeit und Schlüssel prüfen (Ping legt nichts an).
            $ping = Client::ping();

            return $ping['status'] === 200 && ! empty($ping['body']['ok'])
                ? self::result('success', 'test_ok', 'Verbindung und Schlüssel in Ordnung, kein Lead angelegt.')
                : self::map_response($ping, 1, true);
        }
        $key = (string) ($job['idempotency_key'] ?? '');
        if ($key === '') {
            return self::result('fail', 'missing_idempotency_key', 'Kein Idempotency-Key im Job.');
        }
        if (! Settings::is_configured()) {
            return self::result('retry', 'not_configured', 'Connector nicht eingerichtet.');
        }
        $entry = function_exists('form_captain_get_entry_v1') ? form_captain_get_entry_v1((int) ($job['entry_id'] ?? 0), ['meta']) : null;
        if (is_wp_error($entry) || ! is_array($entry)) {
            return self::result('retry', 'entry_unavailable', 'Eintrag nicht lesbar.');
        }
        $payload = LeadPayload::build($job, (string) ($entry['created_gmt'] ?? ''));
        if (! $payload['ok']) {
            // Konfigurations- oder Mappingfehler: nach Korrektur in Form Captain manuell wiederholen.
            return self::result('fail', $payload['code'], 'Lead nicht gesendet: '.$payload['code'].'.');
        }

        $response = Client::post_lead(LeadPayload::encode($payload['body']), $key);
        $result = self::map_response($response, (int) ($job['attempt'] ?? 1));
        if ($result['status'] === 'success' && isset($result['remote_id'])) {
            Ledger::remember((int) $job['entry_id'], (int) $job['form_id'], $result['remote_id']);
        }

        return $result;
    }

    /**
     * Antwort der Plattform → Ergebnis des Vertrags v1 (Vertrag A.5).
     *
     * @param  array{status:int,code:string,body:array<string,mixed>,retry_after:int,error:string}  $response
     * @return array{status:string,code:string,message:string,remote_id?:string}
     */
    public static function map_response(array $response, int $attempt, bool $test = false): array
    {
        $status = $response['status'];
        $code = $response['code'];
        $lead_id = is_string($response['body']['lead_id'] ?? null) ? (string) $response['body']['lead_id'] : '';

        if ($status === 0) {
            $error = $response['error'] !== '' ? $response['error'] : 'remote_unavailable';

            return self::result('retry', $error, 'Plattform nicht erreichbar oder nicht eingerichtet.');
        }
        if (in_array($status, [200, 201, 202], true) && ! $test) {
            if (preg_match('/^L-[0-9A-Za-z]{26}$/', $lead_id) !== 1) {
                return self::result('retry', 'invalid_response', 'Antwort ohne gültige lead_id.');
            }
            $result_code = $status === 202 ? 'accepted_merged' : ($status === 200 ? 'already_created' : 'created');

            return self::result('success', $result_code, '', $lead_id);
        }

        return match (true) {
            $status === 401 && $code === 'signature_expired' => $attempt <= 1
                ? self::result('retry', $code, 'Zeitstempel abgelehnt (Uhrzeit des Servers prüfen); einmal wiederholt.')
                : self::result('fail', $code, 'Zeitstempel abgelehnt: Uhrzeit des Servers prüfen.'),
            $status === 401 => self::result('fail', $code !== '' ? $code : 'signature_invalid', 'Schlüssel-ID oder Geheimnis passt nicht zur Plattform.'),
            $status === 403 => self::result('fail', $code !== '' ? $code : 'forbidden', 'Von der Plattform abgelehnt (Schlüssel widerrufen oder Website nicht freigegeben).'),
            $status === 409 && $code === 'replay_detected' => self::result('retry', $code, 'Nonce bereits verwendet; nächster Versuch mit neuer Nonce.'),
            $status === 409 => self::result('fail', $code !== '' ? $code : 'idempotency_conflict', 'Gleicher Job mit anderem Inhalt; Formular oder Aktion wurden geändert.'),
            $status === 422 => self::result('fail', 'schema_invalid', 'Die Plattform hat den Lead als ungültig abgelehnt (Mapping prüfen).'),
            $status === 429 => self::result('retry', 'rate_limited', 'Zu viele Anfragen; später erneut.'),
            $status >= 500 => self::result('retry', 'remote_unavailable', 'Plattform vorübergehend nicht verfügbar (HTTP '.$status.').'),
            default => self::result('fail', $code !== '' ? $code : 'remote_rejected', 'Von der Plattform abgelehnt (HTTP '.$status.').'),
        };
    }

    /** @return array{status:string,code:string,message:string,remote_id?:string} */
    private static function result(string $status, string $code, string $message, string $remote_id = ''): array
    {
        $result = ['status' => $status, 'code' => substr($code, 0, 60), 'message' => substr($message, 0, 255)];
        if ($remote_id !== '') {
            $result['remote_id'] = $remote_id;
        }

        return $result;
    }
}
