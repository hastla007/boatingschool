<?php

namespace App\Http\Controllers;

use App\Support\NavigationChart;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Liefert einen Kartenausschnitt über eine signierte, kurzlebige Adresse
 * (siehe NavigationChart::url). Ohne Session und ohne Mandant, damit auch die
 * App die Adresse direkt laden kann.
 */
class NavigationChartImageController extends Controller
{
    public function __invoke(string $file): Response
    {
        $found = NavigationChart::locate($file);
        abort_unless($found, 404);
        [$disk, $path] = $found;

        return Storage::disk($disk)->response($path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
