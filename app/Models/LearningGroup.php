<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LearningGroup extends Model
{
    use HasUuids;

    protected $table = 'learning_group';

    protected $fillable = [
        'owner_user_id', 'name', 'status', 'seats', 'scopes', 'valid_from', 'valid_until',
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
