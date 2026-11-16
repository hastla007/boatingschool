<?php

/**
 * Signierte Aufrufe an den Workflow-Eingang der Plattform-App
 * (/api/integrations/v1/workflow/{handshake,commands}), mit eigenem
 * Workflow-Schlüssel (BSW_CONNECTOR_KEY_ID/SECRET), nie mit dem Commerce-
 * Schlüssel des WooCommerce-Connectors.
 *
 * Ergebnis ohne Werte aus der Antwort außer den erwarteten Feldern; ein
 * Fehler ist ein kurzer Code (http_503, timeout, not_configured, …).
 */
defined('ABSPATH') || defined('BSW_CONNECTOR_TESTING') || exit;

final class BSW_App_Client
{
    const BASE = '/api/integrations/v1/workflow/';

    const TIMEOUT = 10;

    /** @return array{status:int, body:array, error:string} status 0 = keine Antwort */
    public static function request(string $endpoint, array $data): array
    {
        $url = BSW_Settings::app_url();
        $key = BSW_Settings::outbound();
        if ($url === '' || $key === null) {
            return ['status' => 0, 'body' => [], 'error' => 'not_configured'];
        }
        $body = (string) wp_json_encode($data);
        $response = wp_remote_post($url.self::BASE.$endpoint, [
            'timeout' => self::TIMEOUT,
            'redirection' => 0,
            'headers' => BSW_Signature::headers($key['key_id'], $key['secret'], $body) + ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => $body,
        ]);
        if (is_wp_error($response)) {
            return ['status' => 0, 'body' => [], 'error' => 'timeout'];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $code = is_string($decoded['code'] ?? null) ? strtolower(preg_replace('/[^A-Za-z0-9_]/', '', $decoded['code'])) : '';

        return ['status' => $status, 'body' => $decoded, 'error' => $status >= 200 && $status < 300 ? '' : ($code !== '' ? $code : 'http_'.$status)];
    }

    /** Contract-Handshake: passt die Hauptversion der App? @return array{ok:bool, code:string, contract_version:?string, actions:array} */
    public static function handshake(int $now, string $version): array
    {
        $r = self::request('handshake', ['op' => 'handshake', 'contract_version' => BSW_Manifest::CONTRACT_VERSION, 'connector_version' => $version]);
        $contract = is_string($r['body']['contract_version'] ?? null) ? $r['body']['contract_version'] : null;
        $ok = $r['status'] === 200 && ($r['body']['compatible'] ?? false) === true;
        BSW_Diagnostics::handshake($ok, $ok ? 'ok' : $r['error'], $contract, $now);
        $actions = [];
        foreach ((array) ($r['body']['actions'] ?? []) as $action) {
            if (is_array($action) && is_string($action['action'] ?? null)) {
                $actions[] = $action['action'];
            }
        }

        return ['ok' => $ok, 'code' => $ok ? 'ok' : $r['error'], 'contract_version' => $contract, 'actions' => $actions];
    }

    /** Ob ein fehlgeschlagener Befehl wiederholt werden darf (Zeitüberschreitung, 429, 5xx, laufend). */
    public static function retryable(array $r): bool
    {
        return $r['status'] === 0 || $r['status'] === 429 || $r['status'] >= 500
            || ($r['status'] === 409 && ($r['error'] ?? '') === 'command_in_progress');
    }
}
