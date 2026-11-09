<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Gerätesitzung der Mobile-API (Token nur als SHA-256-Hash). */
class ApiDeviceSession extends Model
{
    use HasUuids;

    protected $table = 'api_device_session';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'device_name', 'device_platform', 'app_version',
        'access_token_hash', 'access_expires_at', 'refresh_token_hash', 'previous_refresh_token_hash',
        'refresh_expires_at', 'max_expires_at', 'last_used_at', 'created_at', 'revoked_at',
    ];

    protected $hidden = ['access_token_hash', 'refresh_token_hash', 'previous_refresh_token_hash'];

    protected function casts(): array
    {
        return [
            'access_expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'max_expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'created_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
