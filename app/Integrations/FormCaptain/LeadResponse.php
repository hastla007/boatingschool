<?php

namespace App\Integrations\FormCaptain;

use Illuminate\Http\JsonResponse;

/**
 * Antworten des Lead-Eingangs im Format des Vertrags (A.5): `code` in
 * Kleinbuchstaben, kurze Meldung, bei 422 `errors[]` mit JSON-Pointer.
 * Fehlerantworten enthalten nie Werte aus dem Body.
 */
final class LeadResponse
{
    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    public static function make(int $status, string $code, string $message = '', array $extra = [], array $headers = []): JsonResponse
    {
        $body = ['code' => $code] + ($message !== '' ? ['message' => $message] : []) + $extra;

        return response()->json($body, $status, $headers + ['Cache-Control' => 'no-store, private']);
    }
}
