<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Datenexport-Auftrag aus der Mobile-App (Datei liegt befristet im privaten Speicher). */
class ApiDataExport extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'api_data_export';

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'user_id', 'status', 'path', 'expires_at', 'created_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
