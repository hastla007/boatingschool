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

    /**
     * Bug-Review F8: ImportContentQuestions legt bei einem geänderten
     * Reimport eine neue Revision im Status "review" an (siehe dort), statt
     * sie wie Superadmin\QuestionController::update() direkt zu
     * veröffentlichen -- bisher gab es aber keine Stelle, die eine solche
     * Revision überhaupt je veröffentlicht. Da eine neue Revision immer mit
     * revision_no = (bisher höchste) + 1 angelegt wird, ist eine
     * "review"-Revision -- falls vorhanden -- immer zugleich die Revision
     * mit der höchsten revision_no.
     */
    public function pendingReviewRevision(): ?ContentQuestionRevision
    {
        $latest = $this->revisions()->orderByDesc('revision_no')->first();

        return $latest && $latest->editorial_status === 'review' ? $latest : null;
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'module_content', 'question_id', 'module_id')
            ->withPivot(['sort_order', 'required']);
    }
}
