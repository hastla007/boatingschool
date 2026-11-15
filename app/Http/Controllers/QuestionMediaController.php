<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use App\Support\ProtectedMedia;
use App\Support\QuestionMedia;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Liefert ein geschütztes Fragenbild über eine signierte, kurzlebige
 * Adresse (QuestionMedia::url). Ohne Session, damit <img> im Web und die
 * Bild-Loader der App ohne Auth-Header funktionieren; die Signatur bindet
 * Person und Schule, der Zugang wird beim Abruf erneut geprüft (entzogener
 * Kurszugang wirkt sofort, siehe ProtectedMedia).
 */
class QuestionMediaController extends Controller
{
    public function __invoke(Request $request, string $asset): BinaryFileResponse
    {
        $media = MediaAsset::on('pgsql_admin')->find($asset);
        // Keine Auskunft, ob es das Medium gibt.
        abort_unless($media && QuestionMedia::isProtected($media), 404);
        ProtectedMedia::authorizeRequest($request, fn ($user, $tenant) => QuestionMedia::allows($user, $tenant, $media));

        $found = QuestionMedia::locate($media);
        abort_unless($found, 404);
        [, $file] = $found;

        $mime = $media->mime_type && preg_match('#^(image|audio|video)/[A-Za-z0-9.+-]+$#', $media->mime_type) ? $media->mime_type : null;

        return ProtectedMedia::response($file, $mime, ['#^(image|audio|video)/#']);
    }
}
