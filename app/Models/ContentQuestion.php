<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentQuestion extends Model
{
    use HasUuids;

    protected $table = 'content_question';

    public $timestamps = false;

    protected $fillable = [
        'content_id', 'official_number', 'content_role', 'language', 'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ContentQuestionRevision::class, 'question_id');
    }

    public function publishedRevision(): ?ContentQuestionRevision
    {
        return $this->revisions()
            ->where('editorial_status', 'published')
            ->orderByDesc('revision_no')
            ->first();
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'module_content', 'question_id', 'module_id')
            ->withPivot(['sort_order', 'required']);
    }
}
