<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Gruppenlizenz einer Schule (siehe LearningGroupService). */
class LearningGroup extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'learning_group';

    protected $fillable = [
        'tenant_id', 'owner_user_id', 'name', 'status', 'seats', 'scopes', 'valid_from', 'valid_until',
        'join_code', 'source_type', 'source_reference',
    ];

    protected function casts(): array
    {
        return ['seats' => 'integer', 'scopes' => 'array', 'valid_from' => 'datetime', 'valid_until' => 'datetime'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(LearningGroupMember::class, 'group_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && $this->valid_from <= now()
            && ($this->valid_until === null || $this->valid_until->isFuture());
    }
}
