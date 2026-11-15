<?php

namespace App\Http\Controllers;

use App\Support\NavigationChart;
use App\Support\ProtectedMedia;
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
    public function __invoke(Request $request, string $file): Response
    {
        ProtectedMedia::authorizeRequest($request, fn ($user, $tenant) => NavigationChart::allows($user, $tenant, $file));

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
