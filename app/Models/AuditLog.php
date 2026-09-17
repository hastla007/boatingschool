<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasUuids;

    protected $table = 'audit_log';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'actor_user_id', 'action', 'entity_type', 'entity_id',
        'before_data', 'after_data',
    ];

    protected function casts(): array
    {
        return [
            'before_data' => 'array',
            'after_data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public static function record(
        ?string $tenantId,
        ?string $actorUserId,
        string $action,
        string $entityType,
        ?string $entityId,
        ?array $before = null,
        ?array $after = null,
    ): self {
        return static::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_data' => $before,
            'after_data' => $after,
        ]);
    }
}
