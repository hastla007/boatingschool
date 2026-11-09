<?php

namespace App\Api;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Fehlerantworten der Mobile-API im RFC-9457-Stil (application/problem+json)
 * mit stabilem `code` und `request_id`. Interne Meldungen gelangen nie in
 * die Antwort (5xx ohne Details).
 */
class ProblemResponse
{
    public static function render(Throwable $e, Request $request): JsonResponse
    {
        [$status, $code, $title, $detail, $errors, $headers, $extra] = self::describe($e);

        $body = array_filter([
            'type' => 'https://boatingschool/problems/'.strtolower(str_replace('_', '-', $code)),
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'code' => $code,
            'errors' => $errors ?: null,
            'request_id' => RequestId::current($request),
        ], fn ($v) => $v !== null) + $extra;

        return response()->json($body, $status, $headers + [
            'Content-Type' => 'application/problem+json',
            'Cache-Control' => 'no-store, private',
            'X-Request-Id' => RequestId::current($request),
        ], JSON_UNESCAPED_UNICODE);
    }

    /** @return array{0: int, 1: string, 2: string, 3: ?string, 4: array, 5: array, 6: array} */
    private static function describe(Throwable $e): array
    {
        if ($e instanceof ApiProblem) {
            return [$e->getStatusCode(), $e->problemCode, $e->title, $e->getMessage() !== $e->title ? $e->getMessage() : null, $e->errors, $e->getHeaders(), $e->extra];
        }
        if ($e instanceof ValidationException) {
            return [422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, $e->errors(), [], []];
        }
        if ($e instanceof AuthenticationException) {
            return [401, 'UNAUTHENTICATED', 'Anmeldung erforderlich', null, [], [], []];
        }
        if ($e instanceof ThrottleRequestsException) {
            return [429, 'RATE_LIMITED', 'Zu viele Anfragen', 'Bitte später erneut versuchen.', [], $e->getHeaders(), []];
        }
        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return [404, 'RESOURCE_NOT_FOUND', 'Nicht gefunden', null, [], [], []];
        }
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $code = match ($status) {
                401 => 'UNAUTHENTICATED',
                403 => 'FORBIDDEN',
                404 => 'RESOURCE_NOT_FOUND',
                405 => 'METHOD_NOT_ALLOWED',
                409 => 'CONFLICT',
                410 => 'GONE',
                419, 422 => 'VALIDATION_FAILED',
                429 => 'RATE_LIMITED',
                503 => 'SERVICE_UNAVAILABLE',
                default => $status >= 500 ? 'SERVER_ERROR' : 'REQUEST_FAILED',
            };
            // Fachliche Meldungen der gemeinsamen Services (z. B. „Frage ist
            // Teil einer laufenden Prüfung“) sind für Lernende formuliert.
            $detail = $status < 500 && $e->getMessage() !== '' ? $e->getMessage() : null;

            return [$status, $code, $detail ?? 'Anfrage fehlgeschlagen', null, [], $e->getHeaders(), []];
        }

        return [500, 'SERVER_ERROR', 'Interner Fehler', null, [], [], []];
    }
}
