<?php

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Tipp-PDFs (tip.pdf_asset_id, Rechte „licensed“) und Bilder der
 * Videoschritte (video_lesson_step.image_path) von der public- auf die
 * private Disk verschieben; ausgeliefert werden sie danach nur noch über
 * signierte Adressen (App\Support\TipPdf, App\Support\VideoStepImage).
 * Der Pfad in der Datenbank bleibt gleich. Logos, Favicons und öffentliche
 * Fragenbilder bleiben öffentlich.
 *
 * Idempotent: bereits verschobene Dateien werden übersprungen, fehlende
 * Dateien nur gezählt. down() legt die Dateien wieder öffentlich ab.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->move(Storage::disk('public'), Storage::disk('local'), 'verschoben');
    }

    public function down(): void
    {
        $this->move(Storage::disk('local'), Storage::disk('public'), 'zurückverschoben');
    }

    private function move(Filesystem $from, Filesystem $to, string $verb): void
    {
        $moved = $missing = 0;
        foreach ($this->paths() as $path) {
            if ($from->exists($path)) {
                if (! $to->exists($path) && ! $to->put($path, $from->get($path))) {
                    // Nichts löschen, was nicht sicher angekommen ist.
                    throw new RuntimeException("Datei konnte nicht kopiert werden: {$path}");
                }
                $from->delete($path);
                $moved++;
            } elseif (! $to->exists($path)) {
                $missing++;
            }
        }
        if ($moved || $missing) {
            Log::info("Tipp-PDFs/Videoschritt-Bilder: {$moved} {$verb}, {$missing} ohne Datei.");
        }
    }

    /** @return list<string> relative Pfade der Tipp-PDFs und Schrittbilder */
    private function paths(): array
    {
        $isBranding = fn ($s) => $s->selectRaw('1')->from('tenant_branding')
            ->where(fn ($w) => $w->whereColumn('tenant_branding.logo_asset_id', 'media_asset.id')->orWhereColumn('tenant_branding.favicon_asset_id', 'media_asset.id'));

        $tipPdfs = DB::table('media_asset')
            ->where(fn ($q) => $q
                ->whereExists(fn ($s) => $s->selectRaw('1')->from('tip')->whereColumn('tip.pdf_asset_id', 'media_asset.id'))
                // Ersetzte Uploads (ohne Tipp) waren ebenso öffentlich.
                ->orWhere('asset_key', 'like', 'tip-pdf-%'))
            ->whereNotExists($isBranding)
            ->pluck('storage_path');

        // Was als Logo/Favicon oder öffentliches Medium dient, bleibt öffentlich.
        $keepPublic = DB::table('media_asset')
            ->where(fn ($q) => $q->where('rights_status', 'public')->orWhereExists($isBranding))
            ->pluck('storage_path')
            ->map(fn ($p) => $this->clean($p))->filter()->flip();
        $stepImages = DB::table('video_lesson_step')->whereNotNull('image_path')->pluck('image_path')
            ->reject(fn ($p) => $keepPublic->has((string) $this->clean($p)));

        return $tipPdfs->merge($stepImages)
            ->map(fn ($p) => $this->clean($p))
            ->filter()
            ->unique()->values()->all();
    }

    /** Relativer Pfad ohne Traversierung; volle URLs (fremder Host) bleiben außen vor. */
    private function clean(?string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', (string) $path), '/');
        if ($path === '' || preg_match('#^https?://#i', $path) || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
            return null;
        }

        return $path;
    }
};
