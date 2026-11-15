<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use App\Models\Tenant;
use App\Models\User;
use App\Support\QuestionMedia;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Liefert ein geschütztes Fragenbild über eine signierte, kurzlebige
 * Adresse (QuestionMedia::url). Ohne Session, damit <img> im Web und die
 * Bild-Loader der App ohne Auth-Header funktionieren; die Signatur bindet
 * Person und Schule, der Zugang wird beim Abruf erneut geprüft (entzogener
 * Kurszugang wirkt sofort).
 */
class QuestionMediaController extends Controller
{
    public function __invoke(Request $request, TenantContext $context, string $asset): BinaryFileResponse
    {
        $media = MediaAsset::on('pgsql_admin')->find($asset);
        $user = is_string($request->query('u')) ? User::on('pgsql_admin')->find($request->query('u')) : null;
        $tenantId = $request->query('t');
        $tenant = is_string($tenantId) ? Tenant::on('pgsql_admin')->find($tenantId) : null;
        // Keine Auskunft, ob es das Medium gibt.
        abort_unless($media && $user && QuestionMedia::isProtected($media), 404);
        abort_if(is_string($tenantId) && ! $tenant, 404);

        if ($tenant) {
            $context->set($tenant);
        }
        try {
            abort_unless(QuestionMedia::allows($user, $tenant, $media), 404);
        } finally {
            $context->clear();
        }

        $found = QuestionMedia::locate($media);
        abort_unless($found, 404);
        [, $file] = $found;

        $mime = $media->mime_type && preg_match('#^(image|audio|video)/[A-Za-z0-9.+-]+$#', $media->mime_type)
            ? $media->mime_type
            : (mime_content_type($file) ?: 'application/octet-stream');

        // public: false -- sonst setzt BinaryFileResponse „Cache-Control: public“.
        return new BinaryFileResponse($file, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            // Direkt aufgerufen (z. B. SVG) nichts ausführen.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ], false);
    }
}
