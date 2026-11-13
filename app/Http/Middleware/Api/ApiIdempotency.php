<?php

namespace App\Http\Middleware\Api;

use App\Api\ApiProblem;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Idempotency-Key (UUID) für Schreibaufrufe mit Nebenwirkung: gleicher
 * Schlüssel + gleiche Anfrage liefert die gespeicherte Antwort erneut,
 * gleicher Schlüssel mit anderer Anfrage ergibt IDEMPOTENCY_KEY_REUSED.
 * Schlüssel gelten je Konto 24 Stunden; gespeichert werden nur Erfolge.
 * Reservierung des Schlüssels, Wirkung der Aktion und gespeicherte Antwort
 * liegen in derselben Datenbank-Transaktion (alles oder nichts).
 */
class ApiIdempotency
{
    /** status_code einer Reservierung, deren Aktion noch läuft. */
    private const PENDING = 0;

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
            throw $this->inProgress();
        }
    }

    private function once(Request $request, Closure $next, string $key, string $userId, string $fingerprint): Response
    {
        if ($replay = $this->replay($key, $userId, $fingerprint)) {
            return $replay;
        }

        // Schlüssel-Reservierung und Wirkung der Aktion in EINER Transaktion:
        // erst eine „pending“-Zeile (status_code 0) anlegen, dann die Aktion,
        // zuletzt die gespeicherte Antwort -- es committet beides oder
        // nichts. Früher wurde die Antwort erst nach der (schon committeten)
        // Aktion gespeichert; ein Absturz dazwischen führte bei der
        // Wiederholung zur doppelten Ausführung. Der Mandantenkontext
        // (ResolveApiTenant, Session-weites SET) ist zu diesem Zeitpunkt
        // schon gesetzt und liegt bewusst außerhalb dieser Transaktion.
        DB::beginTransaction();
        try {
            // Unabhängig vom Cache-Lock (anderer Cache, abgelaufenes Lock):
            // dieselbe Anfrage läuft schon in einer anderen Verbindung.
            $locked = DB::selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS ok', ["api-idem:{$userId}:{$key}"]);
            if (! $locked->ok) {
                throw $this->inProgress();
            }
            // Nach dem Lock erneut prüfen (inzwischen committet?).
            if ($replay = $this->replay($key, $userId, $fingerprint)) {
                DB::commit();

                return $replay;
            }

            DB::table('api_idempotency_key')->where('user_id', $userId)->where('idempotency_key', $key)->delete();
            DB::table('api_idempotency_key')->insert([
                'user_id' => $userId,
                'idempotency_key' => $key,
                'fingerprint' => $fingerprint,
                'status_code' => self::PENDING,
                'response_body' => null,
                'created_at' => now(),
            ]);

            $response = $next($request);

            // Absturz/Serverfehler (Exceptions kommen hier schon als
            // gerenderte Antwort an): Aktion und Reservierung zurückrollen,
            // damit eine Wiederholung sauber neu beginnt.
            if ($response->getStatusCode() >= 500) {
                DB::rollBack();

                return $response;
            }

            // Review-Runde 13: nur Erfolge speichern. Eine fachliche
            // Ablehnung (z. B. 403 ohne Zugang) blieb sonst mit demselben
            // Schlüssel bestehen, auch nachdem der Grund behoben war. Was die
            // Aktion bei einer Ablehnung bewusst festschreibt (z. B. eine
            // abgelaufene Prüfung beenden), bleibt wie bisher bestehen.
            if ($response->isSuccessful()) {
                DB::table('api_idempotency_key')->where('user_id', $userId)->where('idempotency_key', $key)->update([
                    'status_code' => $response->getStatusCode(),
                    'response_body' => $response->getContent() ?: null,
                ]);
            } else {
                DB::table('api_idempotency_key')->where('user_id', $userId)->where('idempotency_key', $key)->delete();
            }
            DB::commit();
        } catch (UniqueConstraintViolationException) {
            // Parallel committet (ohne gemeinsames Lock): dessen Antwort.
            DB::rollBack();

            return $this->replay($key, $userId, $fingerprint) ?? throw $this->inProgress();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $response;
    }

    private function replay(string $key, string $userId, string $fingerprint): ?Response
    {
        // Review-Runde 13: nur innerhalb der Aufbewahrungsfrist (das
        // tägliche Aufräumen allein ließe bis zu 48 Stunden zu).
        $stored = DB::table('api_idempotency_key')->where('user_id', $userId)->where('idempotency_key', $key)
            ->where('created_at', '>', now()->subHours((int) config('mobile_api.idempotency_hours', 24)))->first();
        if (! $stored) {
            return null;
        }
        if (! hash_equals($stored->fingerprint, $fingerprint)) {
            throw new ApiProblem(409, 'IDEMPOTENCY_KEY_REUSED', 'Idempotency-Key bereits verwendet', 'Dieser Schlüssel gehört zu einer anderen Anfrage.');
        }
        // Eine Reservierung ist nur innerhalb ihrer eigenen Transaktion
        // sichtbar; taucht sie trotzdem auf, läuft die Anfrage noch.
        if ((int) $stored->status_code === self::PENDING) {
            throw $this->inProgress();
        }

        return new JsonResponse(json_decode($stored->response_body ?? 'null', true), $stored->status_code, array_filter([
            'Idempotent-Replayed' => 'true',
            'Content-Type' => $stored->status_code >= 400 ? 'application/problem+json' : null,
        ]), JSON_UNESCAPED_UNICODE);
    }

    private function inProgress(): ApiProblem
    {
        // Review-Runde 13: dieselbe Anfrage läuft noch -- kein 500.
        return new ApiProblem(409, 'CONFLICT', 'Anfrage läuft noch', 'Dieselbe Anfrage wird gerade bearbeitet. Bitte kurz warten und erneut senden.', [], ['Retry-After' => '5']);
    }
}
