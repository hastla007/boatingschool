<?php

namespace App\Support;

use App\Api\CourseAccess;
use App\Models\CourseDefinition;
use App\Models\NavigationTask;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Kartenausschnitte der Navigationsaufgaben (BSH-Seekarten): Lizenzen
 * erlauben die Nutzung meist nur für Kursteilnehmer. Die Bilder liegen
 * deshalb privat und werden nur über kurzlebige, signierte Adressen
 * ausgeliefert. Wie bei QuestionMedia ist die Adresse an Person (u) und
 * Schule (t) gebunden; NavigationChartImageController prüft beim Abruf
 * erneut, ob die Person in dieser Schule Zugang zu einem Kurs mit der
 * Aufgabe hat (entzogener Kurszugang wirkt sofort, eine weitergegebene
 * Adresse nützt nach dem Entzug nichts mehr).
 *
 * Adressen werden nur bei der Ausgabe erzeugt und nie zwischengespeichert.
 */
class NavigationChart
{
    public const DISK = 'local';

    public const DIRECTORY = 'navigation-charts';

    /** Gültigkeit einer Adresse: deckt die längste Prüfung (60 Minuten) samt Navigationsteil ab. */
    public const URL_MINUTES = 90;

    /**
     * Adresse für die Ausgabe (Seite oder API) für die angemeldete Person im
     * aktuellen Schulkontext. Ohne Person: null.
     */
    public static function url(?string $path, ?User $user = null, ?Tenant $tenant = null): ?string
    {
        if (! $path || ! str_starts_with($path, self::DIRECTORY.'/')) {
            return null;
        }

        return ProtectedMedia::signedUrl('navigation-chart.image', self::URL_MINUTES, ['file' => basename($path)], $user, $tenant);
    }

    /**
     * Darf die Person den Kartenausschnitt in dieser Schule sehen? Superadmin
     * immer; sonst aktive Mitgliedschaft in einer laufenden Schule und Zugang
     * zu einem Kurs mit einer Aufgabe, die diesen Ausschnitt nutzt.
     * Erwartet den gesetzten Mandantenkontext der Schule (RLS).
     */
    public static function allows(User $user, ?Tenant $tenant, string $file): bool
    {
        return ProtectedMedia::gate($user, $tenant, function () use ($tenant, $user, $file) {
            // Wie die Seiten und die API der Navigationsaufgaben: voller Kurszugang
            // (bzw. MOBILE_API_DEFAULT_ACCESS=logged_in für angebotene Kurse).
            $access = app(CourseAccess::class);

            return CourseDefinition::on('pgsql_admin')
                ->whereIn('id', NavigationTask::on('pgsql_admin')->where('chart_image_path', self::DIRECTORY.'/'.$file)->select('course_id'))
                ->get()
                ->contains(fn (CourseDefinition $course) => $access->hasCourseAccess($tenant, $user, $course));
        });
    }

    /**
     * Gespeicherte Datei: privat; ältere Uploads ggf. noch auf der public-Disk
     * (dann meldet der Go-live-Check sie, siehe filesOnPublicDisk()).
     *
     * @return array{0: string, 1: string}|null [Disk, relativer Pfad]
     */
    public static function locate(string $file): ?array
    {
        $path = self::DIRECTORY.'/'.$file;
        foreach ([self::DISK, 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return [$disk, $path];
            }
        }

        return null;
    }

    /**
     * Kartenausschnitte, die noch auf der public-Disk liegen (also anonym
     * unter /storage/navigation-charts/... abrufbar sind) -- für den Go-live-Check.
     *
     * @return list<string> relative Pfade
     */
    public static function filesOnPublicDisk(): array
    {
        return array_values(array_filter(Storage::disk('public')->files(self::DIRECTORY), fn ($path) => basename($path) !== '.gitignore'));
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
            Storage::disk('public')->delete($path);
        }
    }
}
