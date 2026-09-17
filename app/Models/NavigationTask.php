<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NavigationTask extends Model
{
    use HasUuids;

    protected $table = 'navigation_task';

    public $timestamps = false;

    protected $fillable = ['course_id', 'task_number', 'scenario_text', 'sort_order'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(NavigationQuestion::class, 'navigation_task_id')->orderBy('sort_order');
    }
}
