<?php

/**
 * Signierte Aufrufe an den Lead-Eingang der Plattform (Vertrag form-captain-lead/1). Nur im Worker von
 * Form Captain bzw. im Admin (Verbindungstest), nie im Absende-Request. Zeitlimit 10 s.
 */

declare(strict_types=1);

namespace BoatingschoolFormConnector;

defined('ABSPATH') || exit;

final class Client
{
    public const TIMEOUT = 10;

    /**
     * Status 0 = keine Antwort (Netz, Timeout); error ist dann ein kurzer Code ohne Personendaten.
     *
     * @return array{status:int,code:string,body:array<string,mixed>,retry_after:int,error:string}
     */
    public static function post_lead(string $body, string $idempotency_key): array
    {
        return self::request('POST', Settings::LEADS_PATH, $body, ['Idempotency-Key' => $idempotency_key]);
    }

    /** @return array{status:int,code:string,body:array<string,mixed>,retry_after:int,error:string} */
    public static function ping(): array
    {
        return self::request('POST', Settings::PING_PATH, '{}');
    }

    /** @return array{status:int,code:string,body:array<string,mixed>,retry_after:int,error:string} */
    public static function delete_lead(string $lead_id): array
    {
        if (preg_match('/^L-[0-9A-Za-z]{26}$/', $lead_id) !== 1) {
            return ['status' => 0, 'code' => '', 'body' => [], 'retry_after' => 0, 'error' => 'invalid_lead_id'];
        }

        return self::request('DELETE', Settings::LEADS_PATH.'/'.$lead_id, '');
    }

    /**
     * @param  array<string,string>  $extra
     * @return array{status:int,code:string,body:array<string,mixed>,retry_after:int,error:string}
     */
    private static function request(string $method, string $path, string $body, array $extra = []): array
    {
        if (! Settings::is_configured()) {
            return ['status' => 0, 'code' => '', 'body' => [], 'retry_after' => 0, 'error' => 'not_configured'];
        }
        $base = Settings::endpoint();
        // Signiert wird der volle Pfad der Ziel-URL (Plattform ggf. in einem Unterverzeichnis).
        $full_path = rtrim((string) parse_url($base, PHP_URL_PATH), '/').$path;
        $headers = Signature::headers(Settings::key_id(), Settings::secret(), $method, $full_path, $body) + $extra
            + ['Content-Type' => 'application/json; charset=utf-8', 'Accept' => 'application/json'];

        $response = wp_remote_request($base.$path, [
            'method' => $method,
            'headers' => $headers,
            'body' => $body === '' ? null : $body,
            'timeout' => self::TIMEOUT,
            'redirection' => 0,
            'sslverify' => true,
        ]);
        if (is_wp_error($response)) {
            $message = strtolower((string) $response->get_error_message());

            return ['status' => 0, 'code' => '', 'body' => [], 'retry_after' => 0, 'error' => str_contains($message, 'timed out') || str_contains($message, 'timeout') ? 'timeout' : 'remote_unavailable'];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $decoded = is_array($decoded) ? $decoded : [];

        return [
            'status' => $status,
            'code' => is_string($decoded['code'] ?? null) ? substr((string) preg_replace('/[^a-z0-9_.\-]/', '', $decoded['code']), 0, 60) : '',
            'body' => $decoded,
            'retry_after' => (int) wp_remote_retrieve_header($response, 'retry-after'),
            'error' => '',
        ];
    }
}
