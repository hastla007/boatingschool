<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Api\CourseFiles;
use App\Http\Controllers\Controller;
use App\Models\CourseFile;
use App\Models\MediaAsset;
use App\Support\QuestionMedia;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Geschützte Dateien der Mobile-API: Kursdateien (private Disk) und
 * Fragenbilder. Nur mit Token und im Kontext der Schule (X-Tenant-Slug);
 * Zugangsregeln siehe CourseFiles. Range-Anfragen (Fortsetzen großer PDFs)
 * übernimmt BinaryFileResponse.
 */
class FileController extends Controller
{
    /** Typen, die der Client direkt anzeigen darf; alles andere nur als Anhang. */
    private const INLINE_TYPES = [
        'application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp',
        'audio/mpeg', 'audio/mp4', 'video/mp4', 'text/plain',
    ];

    public function __construct(private TenantContext $tenantContext, private CourseFiles $files) {}

    public function download(Request $request, string $fileId): BinaryFileResponse
    {
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();

        $file = CourseFile::where('published', true)->find($fileId);
        if ($file) {
            $course = $this->files->offeredCourse($tenant, $file->course_id);
            if (! $course) {
                throw ApiProblem::notFound('Datei');
            }
            if (! $this->files->allowedIds($tenant, $user, $course, $this->files->published($course))->has($file->id)) {
                throw new ApiProblem(403, 'ENTITLEMENT_REQUIRED', 'Kein Zugang', 'Diese Datei ist für dich nicht freigeschaltet.');
            }

            return $this->send(CourseFile::DISK, $file->storage_path, CourseFile::DIRECTORY.'/', $file->mime_type, $file->title);
        }

        // Fragenbild (media_asset an einer Frage); ohne Zugang 404 statt 403.
        $asset = MediaAsset::find($fileId);
        if (! $asset || $asset->status !== 'published' || ! in_array($asset->rights_status, ['licensed', 'public'], true)
            || ! $this->files->allowsMedia($tenant, $user, $asset)) {
            throw ApiProblem::notFound('Datei');
        }

        // Geschützte Bilder liegen privat, öffentliche auf der public-Disk (QuestionMedia).
        $located = QuestionMedia::locate($asset);
        if (! $located) {
            throw ApiProblem::notFound('Datei');
        }

        return $this->send($located[0], (string) QuestionMedia::relativePath($asset), '', $asset->mime_type, null);
    }

    private function send(string $diskName, string $path, string $prefix, ?string $mime, ?string $title): BinaryFileResponse
    {
        // Nur relative Pfade ohne ".." im erwarteten Verzeichnis, real unterhalb der Disk.
        if ($path === '' || ! str_starts_with($path, $prefix) || str_starts_with($path, '/') || str_contains($path, '\\')
            || preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $path) || preg_match('#(^|/)\.\.(/|$)#', $path)) {
            throw ApiProblem::notFound('Datei');
        }
        $disk = Storage::disk($diskName);
        $root = realpath($disk->path(''));
        $absolute = realpath($disk->path($path));
        if ($root === false || $absolute === false || ! str_starts_with($absolute, $root.DIRECTORY_SEPARATOR) || ! is_file($absolute)) {
            throw ApiProblem::notFound('Datei');
        }

        $mime = strtolower(trim((string) $mime)) ?: 'application/octet-stream';
        $inline = in_array($mime, self::INLINE_TYPES, true);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\"]+/u', ' ', (string) $title));
        $filename = $name !== '' ? $name.($extension !== '' && ! str_ends_with(strtolower($name), '.'.$extension) ? '.'.$extension : '') : basename($path);
        $fallback = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename), '_') ?: 'download';

        $response = new BinaryFileResponse($absolute, 200, [
            'Content-Type' => $inline ? $mime : 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], false, null, false, false);
        $response->setContentDisposition($inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename, $fallback);

        return $response;
    }
}
