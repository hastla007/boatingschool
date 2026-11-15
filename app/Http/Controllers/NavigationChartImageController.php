<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Support\NavigationChart;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Liefert einen Kartenausschnitt über eine signierte, kurzlebige Adresse
 * (siehe NavigationChart::url). Ohne Session, damit <img> im Web und die
 * Bild-Loader der App ohne Auth-Header funktionieren; die Signatur bindet
 * Person und Schule, der Zugang wird beim Abruf erneut geprüft (wie
 * QuestionMediaController).
 */
class NavigationChartImageController extends Controller
{
    public function __invoke(Request $request, TenantContext $context, string $file): Response
    {
        $user = is_string($request->query('u')) ? User::on('pgsql_admin')->find($request->query('u')) : null;
        $tenantId = $request->query('t');
        $tenant = is_string($tenantId) ? Tenant::on('pgsql_admin')->find($tenantId) : null;
        // Keine Auskunft, ob es die Datei gibt.
        abort_unless($user, 404);
        abort_if(is_string($tenantId) && ! $tenant, 404);

        if ($tenant) {
            $context->set($tenant);
        }
        try {
            abort_unless(NavigationChart::allows($user, $tenant, $file), 404);
        } finally {
            $context->clear();
        }

        $found = NavigationChart::locate($file);
        abort_unless($found, 404);
        [$disk, $path] = $found;

        return Storage::disk($disk)->response($path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            // Direkt aufgerufen (z. B. SVG) nichts ausführen.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
