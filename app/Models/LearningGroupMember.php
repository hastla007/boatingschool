<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Platz in einer Lerngruppe: offene Einladung (E-Mail) oder Mitglied (Konto). */
class LearningGroupMember extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'learning_group_member';

    protected $fillable = [
        'tenant_id', 'group_id', 'user_id', 'email', 'status', 'invite_token', 'invited_at', 'joined_at',
    ];

    protected $hidden = ['invite_token'];

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
