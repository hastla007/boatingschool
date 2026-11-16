<?php

/**
 * Eingang der Ereignisse der Plattform-App (POST /wp-json/boatingschool-workflow/v1/events).
 *
 * Reihenfolge wie WorkflowCaptain\Security\InboundGuard und der Eingang der
 * App: Größe, Schlüssel, Rate-Limit, Zeitstempel, Nonce-Form, HMAC über
 * "Zeitstempel.Nonce.Body", Replay; danach Zweck ("op": "event"),
 * Vertragsversion, Lizenz, Doppel, Payload-Mapping und Erfassen in
 * Workflow Captain.
 *
 * Antworten (die App wertet "result" bzw. "code" aus):
 * - 200 {"result": "captured"|"duplicate"}: zugestellt (auch beim Doppel --
 *   so gilt eine Wiederholung nach Timeout als zugestellt).
 * - 503 mit Code: vorübergehend (Lizenz, Workflow Captain fehlt, Connector
 *   nicht freigegeben); die App versucht es später erneut.
 * - 4xx mit Code: Signatur, Vertrag oder Inhalt falsch.
 * Kein Wert aus der Anfrage wird zurückgegeben oder protokolliert.
 */
defined('ABSPATH') || defined('BSW_CONNECTOR_TESTING') || exit;

final class BSW_Receiver
{
    const NAMESPACE = 'boatingschool-workflow/v1';

    const MAX_BYTES = 65536;

    const RATE_PER_MINUTE = 600;

    /** Gesehene Event-IDs (Doppel), je ID so lange gemerkt. */
    const SEEN_TTL = 7 * 86400;

    public static function init(): void
    {
        add_action('rest_api_init', [__CLASS__, 'register_route']);
    }

    public static function register_route(): void
    {
        register_rest_route(self::NAMESPACE, '/events', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'rest'],
            // Berechtigung ist die HMAC-Signatur der App (handle()).
            'permission_callback' => '__return_true',
        ]);
    }

    /** @param WP_REST_Request $request */
    public static function rest($request)
    {
        $headers = [];
        foreach ([BSW_Signature::HEADER_KEY, BSW_Signature::HEADER_TIMESTAMP, BSW_Signature::HEADER_NONCE, BSW_Signature::HEADER_SIGNATURE] as $name) {
            $headers[$name] = (string) $request->get_header($name);
        }
        $result = self::handle($headers, (string) $request->get_body(), time());

        return new WP_REST_Response($result['body'], $result['status']);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status:int, body:array}
     */
    public static function handle(array $headers, string $body, int $now): array
    {
        if (strlen($body) > self::MAX_BYTES) {
            return self::refuse(413, 'too_large', $now);
        }
        $key_id = (string) ($headers[BSW_Signature::HEADER_KEY] ?? '');
        $secret = BSW_Settings::inbound_keys()[$key_id] ?? null;
        if ($secret === null) {
            return self::refuse(401, 'unknown_key', $now);
        }
        $rate = 'bsw_rate_'.md5($key_id.'|'.intdiv($now, 60));
        $count = (int) get_transient($rate) + 1;
        set_transient($rate, $count, 120);
        if ($count > self::RATE_PER_MINUTE) {
            return self::refuse(429, 'rate_limited', $now);
        }
        $timestamp = (string) ($headers[BSW_Signature::HEADER_TIMESTAMP] ?? '');
        if (! ctype_digit($timestamp) || abs($now - (int) $timestamp) > BSW_Signature::TOLERANCE) {
            return self::refuse(401, 'stale_timestamp', $now);
        }
        $nonce = (string) ($headers[BSW_Signature::HEADER_NONCE] ?? '');
        if (! preg_match(BSW_Signature::NONCE_PATTERN, $nonce)) {
            return self::refuse(401, 'invalid_nonce', $now);
        }
        if (! BSW_Signature::verify($secret, $timestamp, $nonce, $body, (string) ($headers[BSW_Signature::HEADER_SIGNATURE] ?? ''))) {
            return self::refuse(401, 'bad_signature', $now);
        }
        // Erst nach gültiger Signatur, damit ein Fremder keine Nonces der App verbraucht.
        $nonce_key = 'bsw_nonce_'.md5($key_id.'|'.$nonce);
        if (get_transient($nonce_key) !== false) {
            return self::refuse(409, 'replay', $now);
        }
        set_transient($nonce_key, 1, 2 * BSW_Signature::TOLERANCE);

        $decoded = json_decode($body, true);
        if (! is_array($decoded) || ($decoded['op'] ?? null) !== 'event') {
            return self::refuse(400, 'op_mismatch', $now);
        }
        $contract = is_string($decoded['contract_version'] ?? null) ? $decoded['contract_version'] : '';
        if ((int) strtok($contract, '.') !== (int) strtok(BSW_Manifest::CONTRACT_VERSION, '.')) {
            return self::refuse(422, 'contract_incompatible', $now);
        }
        if (! BSW_License::valid()) {
            return self::refuse(503, 'license_'.BSW_License::status(), $now);
        }
        if (! BSW_Workflow::available()) {
            return self::refuse(503, 'workflow_captain_missing', $now);
        }
        if (BSW_Workflow::runtime() !== null) {
            return self::refuse(503, (string) BSW_Workflow::runtime(), $now);
        }
        if (! BSW_Workflow::registered()) {
            return self::refuse(503, 'connector_not_registered', $now);
        }
        if (! BSW_Workflow::enabled()) {
            return self::refuse(503, 'connector_not_enabled', $now);
        }

        $event = BSW_Payload_Mapper::map($decoded['event'] ?? null);
        if (is_string($event)) {
            return self::refuse(422, $event, $now);
        }
        $seen = 'bsw_seen_'.md5($event['discriminator']);
        if (get_transient($seen) !== false) {
            BSW_Diagnostics::event('duplicate', $now);

            return ['status' => 200, 'body' => ['result' => 'duplicate']];
        }

        $result = BSW_Workflow::capture($event);
        if ($result === 'captured' || $result === 'duplicate') {
            set_transient($seen, 1, self::SEEN_TTL);
            BSW_Diagnostics::event($result, $now);

            return ['status' => 200, 'body' => ['result' => $result]];
        }

        return self::refuse(503, 'capture_'.($result === 'rejected' ? 'rejected' : 'failed'), $now);
    }

    private static function refuse(int $status, string $code, int $now): array
    {
        BSW_Diagnostics::event($code, $now);

        return ['status' => $status, 'body' => ['code' => $code]];
    }
}
