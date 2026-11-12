<?php

namespace App\Http\Middleware\Commerce;

use App\Api\ApiProblem;
use App\Commerce\Signature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nur signierte Aufrufe des Shops (HMAC-SHA256, siehe App\Commerce\Signature):
 * bekannter Schlüssel, Zeitstempel innerhalb der Toleranz, Nonce nur einmal,
 * Signatur über Methode, Pfad, Zeit, Nonce und Body. Ohne konfiguriertes
 * Geheimnis ist die Schnittstelle abgeschaltet.
 */
class VerifyCommerceSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $keyId = (string) $request->header(Signature::HEADER_KEY);
        $timestamp = (string) $request->header(Signature::HEADER_TIMESTAMP);
        $nonce = (string) $request->header(Signature::HEADER_NONCE);
        $signature = (string) $request->header(Signature::HEADER_SIGNATURE);
        $secret = config('commerce.keys')[$keyId] ?? null;

        if (! $secret || ! ctype_digit($timestamp) || ! preg_match('/^[A-Za-z0-9\-]{16,64}$/', $nonce) || $signature === '') {
            throw $this->unauthorized();
        }
        if (abs(time() - (int) $timestamp) > (int) config('commerce.timestamp_tolerance', 300)) {
            throw new ApiProblem(401, 'SIGNATURE_EXPIRED', 'Anfrage abgelaufen', 'Zeitstempel außerhalb der Toleranz -- Uhrzeit des Shop-Servers prüfen.');
        }
        $expected = Signature::sign($secret, $request->method(), '/'.$request->path(), $timestamp, $nonce, $request->getContent());
        if (! hash_equals($expected, strtolower($signature))) {
            throw $this->unauthorized();
        }
        // Wiedergabeschutz: dieselbe Nonce nur einmal innerhalb der Toleranz.
        if (! Cache::add('commerce-nonce:'.$keyId.':'.$nonce, 1, (int) config('commerce.timestamp_tolerance', 300) * 2)) {
            throw new ApiProblem(401, 'SIGNATURE_REPLAYED', 'Anfrage bereits verarbeitet', 'Diese Nonce wurde schon verwendet.');
        }

        $request->attributes->set('commerce_shop_id', (string) config('commerce.shop_id'));

        return $next($request);
    }

    private function unauthorized(): ApiProblem
    {
        return new ApiProblem(401, 'SIGNATURE_INVALID', 'Signatur ungültig', 'Die Anfrage ist nicht (korrekt) signiert.');
    }
}
