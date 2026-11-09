<?php

namespace App\Http\Middleware\Api;

use App\Api\ApiProblem;
use App\Api\RequestId;
use App\Models\PlatformSetting;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
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
        // Review-Runde 13: nur unter der zentralen Domain ohne Schul-Pfad
        // (/{slug}/api/v1/… lief sonst mit den Einstellungen der Schule).
        if ($request->getBaseUrl() !== '') {
            throw new ApiProblem(404, 'RESOURCE_NOT_FOUND', 'Nicht gefunden', 'Die App-Schnittstelle liegt unter /api/v1 der zentralen Domain.');
        }
        $requestId = RequestId::current($request);
        // Review-Runde 13: die Request-ID auch in Log und Fehlertracking,
        // damit sich eine Support-Anfrage dem Eintrag zuordnen lässt.
        Context::add('request_id', $requestId);
        if (function_exists('Sentry\configureScope')) {
            \Sentry\configureScope(fn ($scope) => $scope->setTag('request_id', $requestId));
        }

        $connection = DB::connection();
        app()->terminating(function () use ($connection) {
            $connection->statement('RESET app.current_tenant_id');
            $connection->statement('RESET app.current_user_id');
        });
        DB::statement('RESET app.current_user_id');
        app(TenantContext::class)->clear();

        // Review-Runde 13: zu alte App-Versionen zum Update auffordern.
        $platform = strtolower((string) $request->header('X-App-Platform'));
        $version = (string) $request->header('X-App-Version');
        $minimum = in_array($platform, ['ios', 'android'], true) ? config("mobile_api.minimum_version.{$platform}") : null;
        if ($minimum && $version !== '' && ! $request->is('api/v1/app/config') && version_compare($version, $minimum, '<')) {
            throw new ApiProblem(426, 'APP_UPDATE_REQUIRED', 'Update erforderlich', 'Bitte aktualisiere die App, um weiterzulernen.', [], [], [
                'minimum_version' => $minimum, 'store_url' => config("mobile_api.store_url.{$platform}"),
            ]);
        }

        // Review-Runde 13: der Wartungsmodus der Plattform gilt auch für die App.
        $settings = PlatformSetting::query()->first();
        if ($settings?->maintenance_mode && ! $request->is('api/v1/app/config')) {
            throw new ApiProblem(503, 'MAINTENANCE', 'Wartung', $settings->maintenance_message ?: 'Die Plattform wird gerade gewartet. Bitte später erneut versuchen.', [], ['Retry-After' => '300']);
        }

        $response = $next($request);
        $response->headers->set('X-Request-Id', RequestId::current($request));
        if (! $response->headers->has('Cache-Control') || ! str_contains((string) $response->headers->get('Cache-Control'), 'no-store')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
