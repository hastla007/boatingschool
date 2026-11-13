<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;

/**
 * Kartenausschnitte von der öffentlichen Disk in den privaten Speicher
 * verschieben (Auslieferung nur noch über signierte Adressen). Der Pfad in
 * der Datenbank bleibt gleich (navigation-charts/<datei>).
 */
return new class extends Migration
{
    public function up(): void
    {
        $public = Storage::disk('public');
        $private = Storage::disk('local');
        foreach ($public->files('navigation-charts') as $path) {
            if (! $private->exists($path)) {
                $private->put($path, $public->get($path));
            }
            $public->delete($path);
        }
    }

    public function down(): void
    {
        // Bewusst kein Zurückverschieben auf die öffentliche Disk.
    }
};
