<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningGroupMember extends Model
{
    use HasUuids;

    protected $table = 'learning_group_member';

    protected $fillable = [
        'group_id', 'user_id', 'email', 'status', 'invite_token', 'invited_at', 'joined_at',
    ];

    protected function casts(): array
    {
        return ['invited_at' => 'datetime', 'joined_at' => 'datetime'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(LearningGroup::class, 'group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
