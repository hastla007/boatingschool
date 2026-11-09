<?php

namespace App\Http\Middleware\Api;

use App\Api\ApiProblem;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
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
 * Schlüssel gelten je Konto 24 Stunden; gespeichert werden nur Erfolge.
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
        try {
            return Cache::lock("api-idem:{$userId}:{$key}", 120)->block(10, fn () => $this->once($request, $next, $key, $userId, $fingerprint));
        } catch (LockTimeoutException) {
            // Review-Runde 13: dieselbe Anfrage läuft noch -- kein 500.
            throw new ApiProblem(409, 'CONFLICT', 'Anfrage läuft noch', 'Dieselbe Anfrage wird gerade bearbeitet. Bitte kurz warten und erneut senden.', [], ['Retry-After' => '5']);
        }
    }

    private function once(Request $request, Closure $next, string $key, string $userId, string $fingerprint): Response
    {
        // Review-Runde 13: nur innerhalb der Aufbewahrungsfrist (das
        // tägliche Aufräumen allein ließe bis zu 48 Stunden zu).
        $stored = DB::table('api_idempotency_key')->where('user_id', $userId)->where('idempotency_key', $key)
            ->where('created_at', '>', now()->subHours((int) config('mobile_api.idempotency_hours', 24)))->first();
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

        // Review-Runde 13: nur Erfolge speichern. Eine fachliche Ablehnung
        // (z. B. 403 ohne Zugang) blieb sonst mit demselben Schlüssel
        // bestehen, auch nachdem der Grund behoben war.
        if ($response->isSuccessful()) {
            DB::table('api_idempotency_key')->where('user_id', $userId)->where('idempotency_key', $key)->delete();
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

    }
}
