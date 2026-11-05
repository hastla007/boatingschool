<?php

namespace App\Http\Middleware;

use App\Support\TenantPath;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bootsschule unter CENTRAL_DOMAIN/<slug> (Standard ohne Wildcard-DNS).
 *
 * Läuft als erste globale Middleware, noch vor dem Routing: beginnt der
 * Pfad auf der zentralen Domain mit dem Slug einer Bootsschule, wird der
 * Request so weitergereicht, als wäre die App im Unterverzeichnis /<slug>
 * installiert (Basis-URL). Die Routen bleiben unverändert, route(), url()
 * und Weiterleitungen erzeugen den Präfix automatisch; ResolveTenant
 * findet die Schule über das Request-Attribut.
 *
 * Sitzungs-Cookies gelten nur für diesen Pfad und tragen den Slug im
 * Namen: wie bei getrennten Subdomains hat jede Schule ihre eigene
 * Anmeldung, und das Cookie der zentralen Domain (Superadmin) kollidiert
 * nicht damit.
 */
class ResolveTenantPath
{
    /** Ursprüngliche Werte (für langlebige Prozesse/Tests mit mehreren Requests). */
    private static ?array $defaults = null;

    public function handle(Request $request, Closure $next): Response
    {
        self::$defaults ??= ['cookie' => config('session.cookie'), 'path' => config('session.path')];
        $this->restoreDefaults();

        if ($request->getHost() !== config('app.central_domain') || $request->getBaseUrl() !== '') {
            return $next($request);
        }

        $path = ltrim($request->getPathInfo(), '/');
        $segment = explode('/', $path, 2)[0];
        if (! TenantPath::isTenantSegment($segment)) {
            return $next($request);
        }

        // /<slug> ohne Schrägstrich: auf /<slug>/ umleiten, damit die Basis-URL eindeutig ist.
        if ($path === $segment) {
            $query = $request->getQueryString();

            return redirect()->to($request->getSchemeAndHttpHost().'/'.$segment.'/'.($query ? '?'.$query : ''), 301);
        }

        $script = '/'.$segment.'/index.php';
        $server = array_merge($request->server->all(), [
            'SCRIPT_NAME' => $script,
            'PHP_SELF' => $script,
            'SCRIPT_FILENAME' => public_path('index.php'),
        ]);
        $tenantRequest = $request->duplicate(null, null, null, null, null, $server);
        $tenantRequest->attributes->set(TenantPath::ATTRIBUTE, $segment);

        // Assets (Vite-Build) liegen weiterhin unter der Domain-Wurzel.
        URL::useAssetOrigin($request->getSchemeAndHttpHost());

        // Eigene Sitzung je Schule (Pfad und Name des Cookies).
        $cookiePath = '/'.$segment;
        config([
            'session.path' => $cookiePath,
            'session.cookie' => self::$defaults['cookie'].'_'.str_replace('-', '_', $segment),
        ]);
        app('cookie')->setDefaultPathAndDomain($cookiePath, config('session.domain'), config('session.secure'), config('session.same_site'));

        return $next($tenantRequest);
    }

    private function restoreDefaults(): void
    {
        config(['session.cookie' => self::$defaults['cookie'], 'session.path' => self::$defaults['path']]);
        URL::useAssetOrigin(config('app.asset_url'));
        app('cookie')->setDefaultPathAndDomain(self::$defaults['path'], config('session.domain'), config('session.secure'), config('session.same_site'));
    }
}
