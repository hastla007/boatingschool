<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ContentQuestionRevision extends Model
{
    use HasUuids;

    protected $table = 'content_question_revision';

    public $timestamps = false;

    protected $fillable = [
        'question_id', 'revision_no', 'question_type', 'question_text', 'feedback_correct', 'feedback_incorrect', 'topic',
        'subtopic', 'competency', 'smartmodus_kategorie', 'image_required', 'source_catalog', 'source_version',
        'source_page', 'source_question_id', 'editorial_status', 'rights_status',
        'rights_owner', 'license_reference', 'valid_from', 'valid_to',
        'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'image_required' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ContentQuestion::class, 'question_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ContentAnswer::class, 'revision_id')->orderBy('sort_order');
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'question_media', 'revision_id', 'media_asset_id')
            ->withPivot(['role', 'sort_order']);
    }

    public function correctAnswer(): ?ContentAnswer
    {
        return $this->answers->firstWhere('is_correct', true);
    }
}
