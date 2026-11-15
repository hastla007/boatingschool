<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Offener Antrag auf Kontolöschung aus der Lernenden-App (bis zur Bestätigung per Mail). */
class ApiAccountDeletionRequest extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'api_account_deletion_request';

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'user_id', 'confirm_key_hash', 'requested_at', 'expires_at'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
