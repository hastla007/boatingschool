<?php

namespace App\Support;

use App\Models\MediaAsset;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Bilder zu Prüfungsfragen und Praxistrainer-Karten (media_asset über
 * question_media bzw. praxis_task). Wie die Kartenausschnitte der
 * Navigationsaufgaben (NavigationChart) liegen alle Medien, deren Rechte
 * nicht ausdrücklich „public“ sind (z. B. amtliche ELWIS-Bilder,
 * „licensed“, „review“, „unknown“), auf der privaten Disk und werden nur
 * über kurzlebige, signierte Adressen ausgeliefert. Die Adresse ist an
 * Person und Schule gebunden; die Route prüft beim Abruf erneut, ob die
 * Person in dieser Schule Zugang zu einem Kurs mit dem Medium hat
 * (QuestionMediaController). Öffentliche Medien bleiben auf der
 * public-Disk unter /storage/... abrufbar.
 *
 * Adressen werden nur bei der Ausgabe erzeugt und nie zwischengespeichert.
 */
class QuestionMedia
{
    public const DISK = 'local';

    public const PUBLIC_DISK = 'public';

    public const DIRECTORY = 'question-media';

    /** Gültigkeit einer Adresse (wie bei den Kartenausschnitten, deckt auch lange Prüfungen ab). */
    public const URL_MINUTES = 120;

    /** Nur ausdrücklich öffentliche Medien bleiben ungeschützt. */
    public static function isProtected(MediaAsset $asset): bool
    {
        return $asset->rights_status !== 'public';
    }

    public static function diskFor(?string $rightsStatus): string
    {
        return $rightsStatus === 'public' ? self::PUBLIC_DISK : self::DISK;
    }

    /** Relativer Pfad in der Disk (null bei alten, absoluten Fremd-URLs). */
    public static function relativePath(MediaAsset $asset): ?string
    {
        return ProtectedMedia::relativePath($asset->getRawOriginal('storage_path'));
    }

    /**
     * Adresse für die Ausgabe (Seite oder API): öffentliche Medien wie
     * bisher, geschützte als signierte, kurzlebige Adresse für die
     * angemeldete Person im aktuellen Schulkontext. Ohne Person: null.
     */
    public static function url(?MediaAsset $asset, ?User $user = null, ?Tenant $tenant = null): ?string
    {
        if (! $asset) {
            return null;
        }
        $raw = (string) $asset->getRawOriginal('storage_path');
        if (! self::isProtected($asset) || preg_match('#^https?://#i', $raw)) {
            // Alte absolute URLs (fremder Host) unverändert, siehe MediaAsset::storagePath().
            return $asset->storage_path;
        }

        return ProtectedMedia::signedUrl('question-media.file', self::URL_MINUTES, ['asset' => $asset->id], $user, $tenant);
    }

    /** Hochgeladene Datei passend zu den Rechten speichern; liefert den relativen Pfad. */
    public static function storeUpload(UploadedFile $file, string $directory, ?string $rightsStatus): string
    {
        $path = $file->storeAs($directory, Str::uuid().'.'.$file->extension(), self::diskFor($rightsStatus));
        if ($path === false) {
            throw new RuntimeException('Bild konnte nicht gespeichert werden.');
        }

        return $path;
    }

    /** Datei (z. B. aus einem Seeder/Import) passend zu den Rechten ablegen, nur wenn sie sich geändert hat. */
    public static function put(string $path, string $contents, ?string $rightsStatus): void
    {
        $disk = Storage::disk(self::diskFor($rightsStatus));
        if (! $disk->exists($path) || $disk->get($path) !== $contents) {
            $disk->put($path, $contents);
        }
        // Eine ältere Kopie auf der anderen Disk wäre sonst (öffentlich) weiter abrufbar.
        if ($rightsStatus !== 'public') {
            Storage::disk(self::PUBLIC_DISK)->delete($path);
        }
    }

    /** Datei eines Assets auf beiden Disks löschen. */
    public static function deleteFile(MediaAsset $asset): void
    {
        $path = self::relativePath($asset);
        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
            Storage::disk(self::PUBLIC_DISK)->delete($path);
        }
    }

    /**
     * Gespeicherte Datei eines geschützten Assets: privat, übergangsweise
     * auch noch auf der public-Disk (Altbestand vor der Migration).
     *
     * @return array{0: string, 1: string}|null [Disk, absoluter Pfad]
     */
    public static function locate(MediaAsset $asset): ?array
    {
        return ProtectedMedia::locate(self::relativePath($asset));
    }

    /**
     * Geschützte Fragen-/Praxistrainer-Medien, deren Datei noch auf der
     * public-Disk liegt (also anonym unter /storage/... abrufbar ist) --
     * für den Go-live-Check. Logos, Favicons und Tipp-PDFs zählen nicht.
     *
     * @return Collection<int, string> relative Pfade
     */
    public static function protectedFilesOnPublicDisk(): Collection
    {
        $public = Storage::disk(self::PUBLIC_DISK);

        return MediaAsset::on('pgsql_admin')
            ->where('rights_status', '!=', 'public')
            ->where(fn ($q) => $q
                ->whereExists(fn ($s) => $s->selectRaw('1')->from('question_media')->whereColumn('question_media.media_asset_id', 'media_asset.id'))
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('praxis_task')->whereColumn('praxis_task.media_asset_id', 'media_asset.id')))
            ->get()
            ->map(fn (MediaAsset $asset) => self::relativePath($asset))
            ->filter(fn (?string $path) => $path !== null && $public->exists($path))
            ->unique()->values();
    }

    /**
     * Darf die Person das Medium in dieser Schule sehen? Superadmin immer;
     * sonst aktive Mitgliedschaft in einer laufenden Schule und Zugang zu
     * einem Kurs, in dem eine veröffentlichte oder abgelöste Revision
     * (Prüfungshistorie) bzw. eine aktive Praxistrainer-Karte das Medium nutzt.
     * Erwartet den gesetzten Mandantenkontext der Schule (RLS).
     */
    public static function allows(User $user, ?Tenant $tenant, MediaAsset $asset): bool
    {
        return ProtectedMedia::gate($user, $tenant, function () use ($tenant, $user, $asset) {
            $courseIds = app(EntitlementService::class)->activeCourses($tenant, $user)->pluck('id');

            return $courseIds->isNotEmpty() && self::usedInCourses($asset, $courseIds);
        });
    }

    /** @param  Collection<int, string>  $courseIds */
    private static function usedInCourses(MediaAsset $asset, Collection $courseIds): bool
    {
        $ids = $courseIds->all();
        $questionIds = DB::table('module_content')->join('course_module', 'course_module.module_id', '=', 'module_content.module_id')
            ->whereIn('course_module.course_id', $ids)->select('module_content.question_id')
            ->union(DB::table('exam_paper_question')->join('exam_paper', 'exam_paper.id', '=', 'exam_paper_question.exam_paper_id')
                ->whereIn('exam_paper.course_id', $ids)->select('exam_paper_question.question_id'))
            ->union(DB::table('video_lesson_question')->join('video_lesson', 'video_lesson.id', '=', 'video_lesson_question.video_lesson_id')
                ->join('video_module', 'video_module.id', '=', 'video_lesson.video_module_id')
                ->whereIn('video_module.course_id', $ids)->select('video_lesson_question.question_id'));

        $inQuestion = DB::table('question_media')
            ->join('content_question_revision', 'content_question_revision.id', '=', 'question_media.revision_id')
            ->where('question_media.media_asset_id', $asset->id)
            ->whereIn('content_question_revision.editorial_status', ['published', 'deprecated'])
            ->whereIn('content_question_revision.question_id', $questionIds)
            ->exists();

        return $inQuestion || DB::table('praxis_task')->where('media_asset_id', $asset->id)
            ->where('active', true)->whereIn('course_id', $ids)->exists();
    }
}
