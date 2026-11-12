<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseFile extends Model
{
    use HasUuids;

    protected $table = 'course_file';

    protected $fillable = [
        'course_id', 'category', 'section_key', 'title', 'description', 'storage_path',
        'mime_type', 'size_bytes', 'required_capability', 'sort_order', 'published',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'sort_order' => 'integer', 'published' => 'boolean'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }
}
