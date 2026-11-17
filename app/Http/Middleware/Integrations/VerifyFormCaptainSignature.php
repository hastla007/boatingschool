<?php

namespace App\Http\Middleware\Integrations;

use App\Integrations\FormCaptain\LeadResponse;
use App\Integrations\FormCaptain\LeadSignature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nur signierte Aufrufe des WordPress-Connectors (Vertrag form-captain-lead/1,
 * A.2 und A.6): eigener Schlüssel aus config/form_captain.php (nie der
 * Commerce-Schlüssel), Zeitstempel innerhalb von 300 s, Nonce je Schlüssel
 * nur einmal (10 Minuten), Vergleich in konstanter Zeit, eigene Drosselung
 * je Schlüssel. Ohne konfiguriertes Geheimnis ist der Endpunkt abgeschaltet.
 *
 * Läuft vor admin-db: nicht signierte Anfragen bekommen keine Owner-
 * Verbindung und verbrauchen kein Kontingent.
 */
class VerifyFormCaptainSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $body = $request->getContent();
        if (strlen($body) > (int) config('form_captain.max_body_bytes', 16384)) {
            return LeadResponse::make(400, 'bad_request', 'Body zu groß.');
        }
        // Nur signierte Teile annehmen: keine Query-Parameter, nur JSON.
        if ((string) $request->getQueryString() !== '') {
            return LeadResponse::make(400, 'bad_request', 'Query-Parameter sind nicht erlaubt.');
        }
        if ($body !== '' && ! $request->isJson()) {
            return LeadResponse::make(400, 'bad_request', 'Bitte Content-Type: application/json senden.');
        }

        $keyId = (string) $request->header(LeadSignature::HEADER_KEY);
        $timestamp = (string) $request->header(LeadSignature::HEADER_TIMESTAMP);
        $nonce = (string) $request->header(LeadSignature::HEADER_NONCE);
        $signature = strtolower((string) $request->header(LeadSignature::HEADER_SIGNATURE));

        if ($keyId !== '' && in_array($keyId, (array) config('form_captain.revoked_key_ids', []), true)) {
            return LeadResponse::make(403, 'key_revoked', 'Dieser Schlüssel wurde widerrufen.');
        }
        $secret = (array) config('form_captain.keys', []);
        $secret = $keyId !== '' ? ($secret[$keyId] ?? null) : null;
        if (! is_string($secret) || $secret === '') {
            return LeadResponse::make(401, 'key_unknown', 'Unbekannte Schlüssel-ID.');
        }
        if (! ctype_digit($timestamp) || preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1 || ! str_starts_with($signature, LeadSignature::VERSION.'=')) {
            return LeadResponse::make(401, 'signature_invalid', 'Die Anfrage ist nicht (korrekt) signiert.');
        }
        $tolerance = (int) config('form_captain.timestamp_tolerance', 300);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return LeadResponse::make(401, 'signature_expired', 'Zeitstempel außerhalb der Toleranz -- Uhrzeit des Servers prüfen.');
        }
        $expected = LeadSignature::sign($secret, $request->method(), '/'.$request->path(), $timestamp, $nonce, $body);
        if (! hash_equals($expected, $signature)) {
            return LeadResponse::make(401, 'signature_invalid', 'Die Anfrage ist nicht (korrekt) signiert.');
        }
        // Wiedergabeschutz: dieselbe Nonce je Schlüssel nur einmal.
        if (! Cache::add('form-captain-nonce:'.$keyId.':'.$nonce, 1, max((int) config('form_captain.nonce_ttl', 600), $tolerance * 2))) {
            return LeadResponse::make(409, 'replay_detected', 'Diese Nonce wurde schon verwendet.');
        }

        // Drosselung je Schlüssel (eigene Töpfe, nicht die der Commerce-API).
        foreach (['minute' => [60, 'per_key_minute', 60], 'day' => [86400, 'per_key_day', 1000]] as $window => [$decay, $setting, $default]) {
            $limiterKey = 'form-captain-lead:'.$window.':'.$keyId;
            if (RateLimiter::tooManyAttempts($limiterKey, (int) config('form_captain.rate_limits.'.$setting, $default))) {
                return LeadResponse::make(429, 'rate_limited', 'Zu viele Anfragen.', [], ['Retry-After' => (string) max(1, RateLimiter::availableIn($limiterKey))]);
            }
        }
        RateLimiter::hit('form-captain-lead:minute:'.$keyId, 60);
        RateLimiter::hit('form-captain-lead:day:'.$keyId, 86400);

        // „Zuletzt benutzt“ je Schlüssel für den Superadmin (Rotation, A.2).
        Cache::forever('form-captain-key-last-used:'.$keyId, now()->toIso8601String());
        $request->attributes->set('form_captain_key_id', $keyId);

        return $next($request);
    }
}
