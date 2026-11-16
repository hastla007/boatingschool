<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kursdatei der Mobile-API (plattformweit, ohne Schulbezug; siehe Migration
 * 000599). Die Datei liegt auf der privaten Disk unter course-files/.
 */
class CourseFile extends Model
{
    use HasUuids;

    public const DISK = 'local';

    public const DIRECTORY = 'course-files';

    public const CATEGORIES = ['download', 'section', 'chart'];

    protected $table = 'course_file';

    protected $fillable = [
        'course_id', 'category', 'section_key', 'title', 'description', 'storage_path',
        'mime_type', 'size_bytes', 'required_capability', 'sort_order', 'published', 'source_key',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'sort_order' => 'integer', 'published' => 'boolean'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }

    /** Funktion, die für den Abruf nötig ist (Downloads immer „downloads“, null = voller Kurszugang). */
    public function capability(): ?string
    {
        return $this->category === 'download' ? 'downloads' : $this->required_capability;
    }
}
