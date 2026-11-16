<?php

namespace App\Http\Middleware;

use App\Http\TenantPathRequest;
use App\Support\TenantPath;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
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
 * Namen: jede Schule hat ihre eigene Anmeldung, und das Cookie der
 * zentralen Domain (Superadmin) überschreibt sich nicht mit ihrem.
 *
 * Das ist nur eine Trennung der Anmeldungen, KEINE Sicherheitsgrenze wie
 * bei getrennten Subdomains: alle Schulen und (ohne SUPERADMIN_HOST) der
 * Superadmin teilen sich einen Ursprung. Skript auf einer Schulseite
 * (z. B. eine XSS-Lücke) könnte Seiten anderer Schulen und des Superadmins
 * im selben Browser lesen und Anfragen dorthin schicken; Cookie-Pfade
 * schützen davor nicht. Für den Betrieb deshalb SUPERADMIN_HOST setzen
 * oder den Subdomain-Modus nutzen (deploy/README.md, Go-live-Check).
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
        $tenantRequest = TenantPathRequest::createFrom($request->duplicate(null, null, null, null, null, $server));
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

    /**
     * Nach der Antwort: den ursprünglichen Request wieder als app('request') binden.
     *
     * Der Kernel bindet den Schul-Request beim Routing als app('request');
     * der URL-Generator (und alles andere mit einem rebinding auf
     * 'request') behält ihn sonst über das Request-Ende hinaus. Alles, was
     * danach im selben Prozess url()/route() aufruft, bekäme den Präfix
     * /<slug> -- in Tests mit mehreren Requests landete der nächste
     * Aufruf von /api/v1/… dadurch unter /<slug>/api/v1/… (404), ebenso
     * Code zwischen Requests in langlebigen Prozessen. Läuft nach der
     * terminate-Phase der Routen-Middleware (Session ist gespeichert),
     * die app()->terminating()-Callbacks setzen nur DB-Einstellungen zurück.
     * Sitzungs-Cookie und Asset-Origin setzt handle() zu Beginn des
     * nächsten Requests zurück (restoreDefaults()).
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! app('request') instanceof TenantPathRequest || $request instanceof TenantPathRequest) {
            return;
        }

        app()->instance('request', $request);
        Facade::clearResolvedInstance('request');
    }

    private function restoreDefaults(): void
    {
        config(['session.cookie' => self::$defaults['cookie'], 'session.path' => self::$defaults['path']]);
        URL::useAssetOrigin(config('app.asset_url'));
        app('cookie')->setDefaultPathAndDomain(self::$defaults['path'], config('session.domain'), config('session.secure'), config('session.same_site'));
    }
}
