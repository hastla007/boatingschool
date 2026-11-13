<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Kartenausschnitte der Navigationsaufgaben (BSH-Seekarten): Lizenzen
 * erlauben die Nutzung meist nur für Kursteilnehmer. Die Bilder liegen
 * deshalb privat und werden nur über kurzlebige, signierte Adressen
 * ausgeliefert, die ausschließlich berechtigte Seiten bzw. API-Antworten
 * erzeugen.
 */
class NavigationChart
{
    public const DISK = 'local';

    public const DIRECTORY = 'navigation-charts';

    public const URL_MINUTES = 120;

    public static function url(?string $path): ?string
    {
        if (! $path || ! str_starts_with($path, self::DIRECTORY.'/')) {
            return null;
        }

        return URL::temporarySignedRoute('navigation-chart.image', now()->addMinutes(self::URL_MINUTES), ['file' => basename($path)]);
    }

    /** Gespeicherte Datei (privat; ältere Uploads ggf. noch auf der public-Disk). */
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

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
            Storage::disk('public')->delete($path);
        }
    }
}
