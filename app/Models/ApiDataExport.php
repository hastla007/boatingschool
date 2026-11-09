<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Datenexport-Auftrag aus der Mobile-App (Datei liegt befristet im privaten Speicher). */
class ApiDataExport extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'api_data_export';

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'user_id', 'status', 'path', 'expires_at', 'created_at'];

    /**
     * Review-Runde 13: Exportdateien beim Löschen von Konto, Mitgliedschaft
     * oder Schule sofort mitlöschen (nicht erst beim täglichen Aufräumen).
     * Erst nach dem Commit, damit ein Abbruch keine Datei ohne Zeile übrig lässt.
     *
     * @param  iterable<?string>  $paths
     */
    public static function deleteFilesAfterCommit(iterable $paths): void
    {
        $paths = collect($paths)->filter()->values()->all();
        if ($paths !== []) {
            DB::afterCommit(fn () => Storage::disk('local')->delete($paths));
        }
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
