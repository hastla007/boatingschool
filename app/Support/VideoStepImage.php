<?php

namespace App\Support;

use App\Api\CourseAccess;
use App\Models\CourseDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VideoLessonStep;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Bilder der Schritt-für-Schritt-Galerie unter einem Video
 * (video_lesson_step.image_path, relativer Pfad). Sie sind Kursinhalt:
 * Die Dateien liegen auf der privaten Disk und werden nur über
 * kurzlebige, an Person und Schule gebundene Adressen ausgeliefert
 * (ProtectedMedia, VideoStepImageController). Zugang wie zur Lektion in
 * der API: Funktion „video“ des Kurses (CourseAccess, bei Probezugang nur
 * die ersten N Lektionen); Superadmin immer.
 */
class VideoStepImage
{
    /** Ordner für neue Dateien (Bestandsdateien behalten ihren Pfad). */
    public const DIRECTORY = 'video-steps';

    /** Gültigkeit einer Adresse (wie bei den Fragenbildern). */
    public const URL_MINUTES = 120;

    /** Typen, die inline ausgeliefert werden (alles andere als Anhang). */
    public const INLINE_TYPES = ['#^image/(png|jpeg|gif|webp|avif)$#'];

    /** Adresse für die Ausgabe (Seite oder API); ohne Bild oder Person: null. */
    public static function url(?VideoLessonStep $step, ?User $user = null, ?Tenant $tenant = null): ?string
    {
        $raw = (string) $step?->image_path;
        if ($raw === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $raw)) {
            // Fremd gehostete Bilder (volle URL) unverändert.
            return $raw;
        }

        return ProtectedMedia::signedUrl('video-step.image', self::URL_MINUTES, ['step' => $step->id], $user, $tenant);
    }

    /** Darf die Person das Bild dieses Schritts sehen? Erwartet den Mandantenkontext der Schule. */
    public static function allows(User $user, ?Tenant $tenant, VideoLessonStep $step): bool
    {
        return ProtectedMedia::gate($user, $tenant, function () use ($user, $tenant, $step) {
            $lesson = $step->lesson()->with('module')->first();
            $course = $lesson?->module ? CourseDefinition::on('pgsql_admin')->find($lesson->module->course_id) : null;
            if (! $course) {
                return false;
            }
            $access = app(CourseAccess::class);
            if (! $access->hasFeatureAccess($tenant, $user, $course, 'video')) {
                return false;
            }
            // Probezugang: dieselbe geordnete Lektionsliste wie Web und API.
            $ordered = $course->videoModules()->with('lessons')->get()->flatMap->lessons->values();

            return $access->allowsLimitedItem($tenant, $user, $course, 'video', $ordered, (string) $lesson->id);
        });
    }

    /**
     * Relative Pfade aller Schrittbilder, die auf die private Disk gehören
     * (ohne volle URLs und ohne Dateien, die zugleich Logo/Favicon oder
     * öffentliches Medium sind -- wie die Migration 000820).
     *
     * @return Collection<int, string>
     */
    public static function protectedPaths(): Collection
    {
        $db = DB::connection('pgsql_admin');
        $keepPublic = $db->table('media_asset')
            ->where(fn ($q) => $q->where('rights_status', 'public')
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('tenant_branding')
                    ->where(fn ($w) => $w->whereColumn('tenant_branding.logo_asset_id', 'media_asset.id')->orWhereColumn('tenant_branding.favicon_asset_id', 'media_asset.id'))))
            ->pluck('storage_path')->map(fn ($p) => ProtectedMedia::relativePath($p))->filter()->flip();

        return $db->table('video_lesson_step')->whereNotNull('image_path')->pluck('image_path')
            ->map(fn ($p) => ProtectedMedia::relativePath($p))
            ->filter(fn (?string $p) => $p !== null && ! $keepPublic->has($p))
            ->unique()->values();
    }

    /**
     * Schrittbilder, deren Datei noch auf der public-Disk liegt (also anonym
     * unter /storage/... abrufbar ist) -- für den Go-live-Check.
     *
     * @return Collection<int, string> relative Pfade
     */
    public static function filesOnPublicDisk(): Collection
    {
        $public = Storage::disk(ProtectedMedia::PUBLIC_DISK);

        return self::protectedPaths()->filter(fn (string $path) => $public->exists($path))->values();
    }
}
