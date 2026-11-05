<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Bootsschule unter einem Pfad der zentralen Domain
 * (CENTRAL_DOMAIN/<slug>, siehe ResolveTenantPath). Der Slug wird dort wie
 * ein Unterverzeichnis behandelt: die Routen bleiben unverändert, alle
 * erzeugten Links enthalten den Präfix automatisch.
 */
class TenantPath
{
    /** Request-Attribut mit dem Slug aus dem Pfad. */
    public const ATTRIBUTE = 'tenant_path_slug';

    /** Dateien und Bereiche, die nie als Bootsschule gelten. */
    private const ALWAYS_RESERVED = ['build', 'storage', 'vendor', 'index.php', 'favicon.ico', 'robots.txt', 'up', 'tls-check'];

    private static ?array $routeSegments = null;

    /** Slug der aktuell per Pfad aufgerufenen Bootsschule. */
    public static function current(?Request $request = null): ?string
    {
        $request ??= request();

        return $request?->attributes->get(self::ATTRIBUTE);
    }

    /** Zentrale Domain ohne Bootsschulen-Pfad (Superadmin, Schulauswahl). */
    public static function isCentralRoot(Request $request): bool
    {
        return $request->getHost() === config('app.central_domain') && self::current($request) === null;
    }

    /** Erste Pfadsegmente aller Routen und feste Bereiche: dürfen kein Slug sein. */
    public static function reservedSegments(): array
    {
        if (self::$routeSegments === null) {
            self::$routeSegments = collect(Route::getRoutes()->getRoutes())
                ->map(fn ($route) => explode('/', trim($route->uri(), '/'))[0])
                ->reject(fn ($segment) => $segment === '' || str_starts_with($segment, '{'))
                ->map(fn ($segment) => strtolower($segment))
                ->merge(self::ALWAYS_RESERVED)
                ->unique()->values()->all();
        }

        return self::$routeSegments;
    }

    /** Ist das erste Pfadsegment der Slug einer Bootsschule? */
    public static function isTenantSegment(string $segment): bool
    {
        if ($segment === '' || ! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $segment) || in_array($segment, self::reservedSegments(), true)) {
            return false;
        }

        // Owner-Verbindung: vor jeder Tenant-/RLS-Auflösung (wie ResolveTenant::resolveViaCustomDomain()).
        return DB::connection('pgsql_admin')->table('tenant')->where('slug', $segment)->exists();
    }

    public static function flushRouteSegments(): void
    {
        self::$routeSegments = null;
    }
}
