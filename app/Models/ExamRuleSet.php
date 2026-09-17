<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamRuleSet extends Model
{
    use HasUuids;

    protected $table = 'exam_rule_set';

    public $timestamps = false;

    protected $fillable = [
        'course_id', 'version', 'valid_from', 'valid_until', 'time_limit_seconds',
        'passing_rule', 'status', 'verified_by', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'passing_rule' => 'array',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }

    public function blueprints(): HasMany
    {
        return $this->hasMany(ExamBlueprint::class, 'rule_set_id');
    }

    public function isVerified(): bool
    {
        return $this->status === 'published' && $this->verified_at !== null;
    }
}
