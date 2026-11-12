<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Concerns\\HasUuids;
use Illuminate\\Database\\Eloquent\\Model;

/** Datenschutzantrag zur Kontolöschung aus der Lernenden-App. */
class ApiAccountDeletionRequest extends Model
{
    use HasUuids;

    protected $table = 'api_account_deletion_request';

    protected $fillable = [
        'user_id', 'confirm_key_hash', 'status', 'requested_at', 'confirmed_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
