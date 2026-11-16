<?php

/**
 * Signierte Aufrufe an die Commerce-API der App (/api/commerce/v1).
 */
defined('ABSPATH') || exit;

final class BS_Api_Client
{
    /**
     * @param  string  $method  GET|POST|PATCH.
     * @param  string  $path  Pfad unterhalb von /api/commerce/v1, z. B. "/accounts".
     * @param  array|null  $data  JSON-Body.
     * @param  array  $extra_headers  Zusätzliche Header (z. B. Idempotency-Key).
     * @return array{status: int|null, body: array|null, error: string|null}
     */
    public static function request(string $method, string $path, ?array $data = null, array $extra_headers = []): array
    {
        $settings = BS_Settings::get();
        if ($settings['app_url'] === '' || $settings['secret'] === '') {
            return ['status' => null, 'body' => null, 'error' => 'Plugin nicht konfiguriert (App-Adresse/Geheimnis fehlen).'];
        }

        $full_path = '/api/commerce/v1/'.ltrim($path, '/');
        $body = $data === null ? '' : wp_json_encode($data);
        $headers = BS_Signature::headers($settings['key_id'], $settings['secret'], $method, $full_path, $body)
            + [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]
            + $extra_headers;

        $response = wp_remote_request(
            untrailingslashit($settings['app_url']).$full_path,
            [
                'method' => $method,
                'headers' => $headers,
                'body' => $body === '' ? null : $body,
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            BS_Log::error('Plattform nicht erreichbar', ['method' => $method, 'path' => $full_path, 'error' => $response->get_error_message()]);

            return ['status' => null, 'body' => null, 'error' => $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $error = null;
        if ($status >= 400) {
            $error = is_array($decoded) ? trim(($decoded['code'] ?? '').': '.($decoded['detail'] ?? $decoded['title'] ?? '')) : 'HTTP '.$status;
            // Ohne Body und Header: Anfragen können Verknüpfungscodes enthalten,
            // Header die Signatur. Die Fehlertexte der App enthalten keine Codewerte.
            BS_Log::error(
                'API-Fehler',
                [
                    'method' => $method,
                    'path' => $full_path,
                    'http_status' => $status,
                    'error_code' => is_array($decoded) ? (string) ($decoded['code'] ?? '') : '',
                    'error' => $error,
                ]
            );
        }

        return [
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : null,
            'error' => $error,
        ];
    }
}
