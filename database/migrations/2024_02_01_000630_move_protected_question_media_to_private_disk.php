<?php

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Fragen- und Praxistrainer-Bilder, deren Rechte nicht „public“ sind
 * (amtliche ELWIS-Bilder, Uploads, ungeklärte Rechte), von der public- auf
 * die private Disk verschieben; ausgeliefert werden sie danach nur noch
 * über signierte Adressen (App\Support\QuestionMedia). Der Pfad in der
 * Datenbank bleibt gleich. Logos, Favicons und Tipp-PDFs bleiben öffentlich.
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
                    throw new RuntimeException("Fragenbild konnte nicht kopiert werden: {$path}");
                }
                $from->delete($path);
                $moved++;
            } elseif (! $to->exists($path)) {
                $missing++;
            }
        }
        if ($moved || $missing) {
            Log::info("Fragenbilder: {$moved} {$verb}, {$missing} ohne Datei.");
        }
    }

    /** @return list<string> relative Pfade geschützter Fragen-/Praxistrainer-Medien */
    private function paths(): array
    {
        return DB::table('media_asset')
            ->where('rights_status', '!=', 'public')
            ->where('storage_path', 'not like', 'http://%')
            ->where('storage_path', 'not like', 'https://%')
            ->where(fn ($q) => $q
                ->whereExists(fn ($s) => $s->selectRaw('1')->from('question_media')->whereColumn('question_media.media_asset_id', 'media_asset.id'))
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('praxis_task')->whereColumn('praxis_task.media_asset_id', 'media_asset.id'))
                ->orWhere('storage_path', 'like', 'question-media/%'))
            // Sicherheitshalber: was auch als Logo/Favicon/Tipp dient, bleibt öffentlich.
            ->whereNotExists(fn ($s) => $s->selectRaw('1')->from('tenant_branding')
                ->where(fn ($w) => $w->whereColumn('tenant_branding.logo_asset_id', 'media_asset.id')->orWhereColumn('tenant_branding.favicon_asset_id', 'media_asset.id')))
            ->whereNotExists(fn ($s) => $s->selectRaw('1')->from('tip')->whereColumn('tip.pdf_asset_id', 'media_asset.id'))
            ->pluck('storage_path')
            ->map(fn (string $p) => ltrim($p, '/'))
            ->reject(fn (string $p) => $p === '' || in_array('..', explode('/', $p), true))
            ->unique()->values()->all();
    }
};
