<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Einmal-Code für die Anmeldung der eingebetteten Webansicht aus der App. */
class ApiMobileWebSession extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'api_mobile_web_session';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'user_id', 'api_device_session_id', 'code_hash', 'verifier_hash',
        'redirect_path', 'expires_at', 'used_at', 'created_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
