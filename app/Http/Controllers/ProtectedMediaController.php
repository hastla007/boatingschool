<?php

namespace App\Http\Controllers;

use App\Models\Tip;
use App\Models\VideoLessonStep;
use App\Support\ProtectedMedia;
use App\Support\TipPdf;
use App\Support\VideoStepImage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Tipp-PDFs und Bilder der Videoschritte über signierte, kurzlebige
 * Adressen (TipPdf::url, VideoStepImage::url). Wie QuestionMediaController
 * ohne Session, damit Links im Web und die Loader der App ohne Auth-Header
 * funktionieren; die Signatur bindet Person und Schule, der Zugang wird
 * beim Abruf erneut geprüft (ProtectedMedia::authorizeRequest).
 */
class ProtectedMediaController extends Controller
{
    public function tipPdf(Request $request, string $tip): BinaryFileResponse
    {
        $model = Tip::on('pgsql_admin')->with('pdfAsset')->find($tip);
        // Keine Auskunft, ob es den Tipp gibt.
        abort_unless($model?->pdfAsset, 404);
        ProtectedMedia::authorizeRequest($request, fn ($user, $tenant) => TipPdf::allows($user, $tenant, $model));

        $found = ProtectedMedia::locate(ProtectedMedia::relativePath($model->pdfAsset->getRawOriginal('storage_path')));
        abort_unless($found, 404);

        return ProtectedMedia::response($found[1], null, ['#^application/pdf$#'], ProtectedMedia::fileName($model->title, 'pdf', 'tipp'));
    }

    public function videoStep(Request $request, string $step): BinaryFileResponse
    {
        $model = VideoLessonStep::on('pgsql_admin')->find($step);
        abort_unless($model && $model->image_path, 404);
        ProtectedMedia::authorizeRequest($request, fn ($user, $tenant) => VideoStepImage::allows($user, $tenant, $model));

        $found = ProtectedMedia::locate(ProtectedMedia::relativePath($model->image_path));
        abort_unless($found, 404);

        return ProtectedMedia::response($found[1], null, VideoStepImage::INLINE_TYPES);
    }
}
