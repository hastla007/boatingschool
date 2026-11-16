<?php

namespace App\Http\Middleware\Workflow;

use App\Api\ApiProblem;
use App\Workflow\WorkflowKeys;
use App\Workflow\WorkflowSignature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Eingang des Workflow-Vertrags (WF-CUST1-05). Eine feste Reihenfolge für
 * jeden Endpunkt, wie WorkflowCaptain\Security\InboundGuard:
 *
 *   1. Größe des rohen Bodys (vor jeder Auswertung)
 *   2. Schlüssel bekannt (nur Workflow-Schlüssel, nie Commerce-Schlüssel)
 *   3. Rate-Limit je Schlüssel (eigener Topf)
 *   4. Zeitstempel im Fenster (Standard ±300 s)
 *   5. HMAC-SHA256 über "Zeitstempel.Nonce.Body", Vergleich in konstanter Zeit
 *   6. Nonce noch nicht gesehen (dauerhaft in workflow_nonce)
 *   7. nur JSON, keine Query-Parameter, "op" im Body passt zum Endpunkt
 *
 * Jede Ablehnung ist ein fester Code; nichts aus der Anfrage wird
 * zurückgegeben oder protokolliert. Ohne konfigurierten Schlüssel ist der
 * Eingang abgeschaltet (401).
 */
class VerifyWorkflowSignature
{
    public function handle(Request $request, Closure $next, string $op = ''): Response
    {
        $body = $request->getContent();
        if (strlen($body) > (int) config('workflow.max_body_bytes', 65536)) {
            throw new ApiProblem(413, 'PAYLOAD_TOO_LARGE', 'Anfrage zu groß', 'Der Body überschreitet die zulässige Größe.');
        }

        $keyId = (string) $request->header(WorkflowSignature::HEADER_KEY);
        $key = WorkflowKeys::find($keyId);
        if (! $key) {
            throw $this->unauthorized();
        }

        $limit = max(1, (int) config('workflow.rate_limit_per_minute', 60));
        $limiterKey = 'workflow-key:'.$keyId;
        if (RateLimiter::tooManyAttempts($limiterKey, $limit)) {
            throw new ApiProblem(429, 'RATE_LIMITED', 'Zu viele Anfragen', 'Bitte später erneut versuchen.', [], ['Retry-After' => (string) max(1, RateLimiter::availableIn($limiterKey))]);
        }
        RateLimiter::hit($limiterKey, 60);

        $timestamp = (string) $request->header(WorkflowSignature::HEADER_TIMESTAMP);
        $tolerance = (int) config('workflow.timestamp_tolerance', 300);
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $tolerance) {
            throw new ApiProblem(401, 'SIGNATURE_EXPIRED', 'Anfrage abgelaufen', 'Zeitstempel außerhalb der Toleranz -- Uhrzeit des Servers prüfen.');
        }
        $nonce = (string) $request->header(WorkflowSignature::HEADER_NONCE);
        if (! preg_match(WorkflowSignature::NONCE_PATTERN, $nonce)) {
            throw $this->unauthorized();
        }
        if (! WorkflowSignature::verify($key['secret'], $timestamp, $nonce, $body, (string) $request->header(WorkflowSignature::HEADER_SIGNATURE))) {
            throw $this->unauthorized();
        }
        // Erst nach gültiger Signatur: ein Fremder kann keine Nonces des Absenders verbrauchen.
        $fresh = DB::connection('pgsql_admin')->table('workflow_nonce')->insertOrIgnore([
            'key_id' => $keyId, 'nonce' => $nonce, 'expires_at' => now()->addSeconds(2 * $tolerance),
        ]);
        if ($fresh !== 1) {
            throw new ApiProblem(409, 'SIGNATURE_REPLAYED', 'Anfrage bereits verarbeitet', 'Diese Nonce wurde schon verwendet.');
        }

        if ((string) $request->getQueryString() !== '') {
            throw new ApiProblem(400, 'QUERY_NOT_ALLOWED', 'Query-Parameter nicht erlaubt', 'Daten nur im signierten JSON-Body.');
        }
        if (! $request->isJson()) {
            throw new ApiProblem(415, 'UNSUPPORTED_MEDIA_TYPE', 'Nur JSON', 'Bitte Content-Type: application/json senden.');
        }
        $decoded = json_decode($body, true);
        if (! is_array($decoded) || ($op !== '' && ($decoded['op'] ?? null) !== $op)) {
            throw new ApiProblem(400, 'OP_MISMATCH', 'Falscher Zweck', 'Der signierte Body passt nicht zu diesem Endpunkt.');
        }

        $request->attributes->set('workflow_key_id', $keyId);
        $request->attributes->set('workflow_scopes', $key['scopes']);
        $request->attributes->set('workflow_body', $decoded);

        return $next($request);
    }

    private function unauthorized(): ApiProblem
    {
        return new ApiProblem(401, 'SIGNATURE_INVALID', 'Signatur ungültig', 'Die Anfrage ist nicht (korrekt) signiert.');
    }
}
