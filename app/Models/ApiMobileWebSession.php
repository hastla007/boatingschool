<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ApiMobileWebSession extends Model
{
    use HasUuids;

    protected $table = 'api_mobile_web_session';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'api_device_session_id', 'code_hash', 'verifier_hash',
        'redirect_path', 'expires_at', 'used_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
