<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoLesson extends Model
{
    use HasUuids;

    protected $table = 'video_lesson';

    public $timestamps = false;

    protected $fillable = ['video_module_id', 'title', 'video_url', 'duration_seconds', 'sort_order'];

    public function module(): BelongsTo
    {
        return $this->belongsTo(VideoModule::class, 'video_module_id');
    }

    public function formattedDuration(): string
    {
        if (! $this->duration_seconds) {
            return '';
        }

        return sprintf('%d:%02d', intdiv($this->duration_seconds, 60), $this->duration_seconds % 60);
    }
}
