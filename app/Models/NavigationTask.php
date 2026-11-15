<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class NavigationTask extends Model
{
    use HasUuids;

    protected $table = 'navigation_task';

    public $timestamps = false;

    protected $fillable = ['course_id', 'task_number', 'scenario_text', 'hint', 'sort_order', 'chart_image_path', 'chart_caption', 'source_task_id'];

    /**
     * IDs derselben Aufgabe in allen Kursen: Quelle und ihre gespiegelten
     * Kopien (source_task_id, siehe ComboNavigationTaskSeeder) -- für die
     * Sperre der Aufgabe einer laufenden Prüfung (QA-L-6).
     *
     * @return Collection<int, string>
     */
    public static function linkedIds(string $taskId): Collection
    {
        $root = static::whereKey($taskId)->value('source_task_id') ?? $taskId;

        return static::where('id', $root)->orWhere('source_task_id', $root)->pluck('id')
            ->push($taskId)->unique()->values();
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(NavigationQuestion::class, 'navigation_task_id')->orderBy('sort_order');
    }
}
