<?php

namespace App\Http\Middleware\Api;

use App\Api\RequestId;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Jede Mobile-API-Anfrage: JSON-Antworten, Request-ID, keine Zwischen-
 * speicherung persönlicher Daten, frischer RLS-Kontext (wird am Ende der
 * Anfrage zurückgesetzt, wie bei ResolveTenant).
 */
class PrepareApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');
        RequestId::current($request);

        $connection = DB::connection();
        app()->terminating(function () use ($connection) {
            $connection->statement('RESET app.current_tenant_id');
            $connection->statement('RESET app.current_user_id');
        });
        DB::statement('RESET app.current_user_id');
        app(TenantContext::class)->clear();

        $response = $next($request);
        $response->headers->set('X-Request-Id', RequestId::current($request));
        if (! $response->headers->has('Cache-Control') || ! str_contains((string) $response->headers->get('Cache-Control'), 'no-store')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
