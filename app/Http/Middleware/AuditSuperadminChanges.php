<?php

namespace App\Http\Middleware;

use App\Api\CourseAccess;
use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Jede Änderung im Superadmin-Bereich (POST/PUT/PATCH/DELETE) landet im
 * Protokoll -- auch in Editoren, die (noch) keinen eigenen, ausführlicheren
 * Eintrag schreiben (Fragen, Kurse, Videos, Tipps, Vorlagen …). Hat der
 * Controller selbst protokolliert, entsteht kein zweiter Eintrag.
 */
class AuditSuperadminChanges
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $before = AuditLog::$recordedThisRequest;
        $response = $next($request);

        $succeeded = $response->getStatusCode() < 400 && ! session()->has('errors');
        if ($succeeded) {
            // Inhalte könnten sich geändert haben: Kurs-Zwischenspeicher der App neu berechnen.
            CourseAccess::bumpContentVersion();
        }
        if ($succeeded && AuditLog::$recordedThisRequest === $before && $request->user()) {
            $route = $request->route();
            $parameters = collect($route?->parameters() ?? [])
                ->map(fn ($value) => is_object($value) && method_exists($value, 'getKey') ? $value->getKey() : (is_scalar($value) ? $value : null))
                ->filter()->all();
            $fields = collect($request->except(['_token', '_method', 'password', 'password_confirmation', 'file']))->keys()->take(30)->all();

            AuditLog::record(null, $request->user()->id, 'superadmin.change', 'route', null, null, [
                'route' => $route?->getName(), 'method' => $request->method(), 'parameters' => $parameters, 'fields' => $fields,
            ]);
        }

        return $response;
    }
}
