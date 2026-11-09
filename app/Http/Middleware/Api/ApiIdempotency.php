<?php

namespace App\Http\Middleware\Api;

use App\Api\ApiProblem;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key (UUID) für Schreibaufrufe mit Nebenwirkung: gleicher
 * Schlüssel + gleiche Anfrage liefert die gespeicherte Antwort erneut,
 * gleicher Schlüssel mit anderer Anfrage ergibt IDEMPOTENCY_KEY_REUSED.
 * Schlüssel gelten je Konto und werden nach 24 Stunden gelöscht.
 */
class ApiIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! Str::isUuid($key)) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['Idempotency-Key' => ['Bitte eine UUID als Idempotency-Key mitsenden.']]);
        }
        $key = strtolower($key);
        $userId = $request->user()->id;
        $fingerprint = hash('sha256', $request->method().' '.$request->path().' '.(string) $request->header('X-Tenant-Slug').' '.$request->getContent());

        // Gleichzeitige Wiederholungen nacheinander abarbeiten.
        return Cache::lock("api-idem:{$userId}:{$key}", 30)->block(10, function () use ($request, $next, $key, $userId, $fingerprint) {
            $stored = DB::table('api_idempotency_key')->where('user_id', $userId)->where('idempotency_key', $key)->first();
            if ($stored) {
                if (! hash_equals($stored->fingerprint, $fingerprint)) {
                    throw new ApiProblem(409, 'IDEMPOTENCY_KEY_REUSED', 'Idempotency-Key bereits verwendet', 'Dieser Schlüssel gehört zu einer anderen Anfrage.');
                }

                return new JsonResponse(json_decode($stored->response_body ?? 'null', true), $stored->status_code, array_filter([
                    'Idempotent-Replayed' => 'true',
                    'Content-Type' => $stored->status_code >= 400 ? 'application/problem+json' : null,
                ]), JSON_UNESCAPED_UNICODE);
            }

            $response = $next($request);

            // Nur abgeschlossene Ergebnisse speichern (Erfolg oder fachliche
            // Ablehnung) -- Serverfehler dürfen wiederholt werden.
            if ($response->getStatusCode() < 500 && $response->getStatusCode() !== 429) {
                DB::table('api_idempotency_key')->insert([
                    'user_id' => $userId,
                    'idempotency_key' => $key,
                    'fingerprint' => $fingerprint,
                    'status_code' => $response->getStatusCode(),
                    'response_body' => $response->getContent() ?: null,
                    'created_at' => now(),
                ]);
            }

            return $response;
        });
    }
}
