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
     * Revision überhaupt je veröffentlicht.
     *
     * Bug-Review (AA9: Wartende Import-Änderung verschwindet): früher wurde
     * dafür angenommen, eine "review"-Revision sei -- da revision_no stets
     * aufsteigend vergeben wird -- immer zugleich die insgesamt letzte
     * Revision der Frage. Speichert der Superadmin aber über den Editor
     * (QuestionController::update()), während eine solche Revision noch auf
     * Freigabe wartet, legt das eine NEUE, bereits veröffentlichte Revision
     * mit einer noch höheren revision_no an, ohne die wartende Review-
     * Revision anzutasten (siehe dortiger Bug-Review T3) -- diese Annahme
     * galt dann nicht mehr, und die wartende Revision verschwand dadurch
     * unbemerkt und dauerhaft aus der Vorschau sowie aus der Fragenliste.
     * Gesucht wird deshalb gezielt nach dem Status, nicht nach der Position.
     */
    public function pendingReviewRevision(): ?ContentQuestionRevision
    {
        return $this->revisions()
            ->where('editorial_status', 'review')
            ->orderByDesc('revision_no')
            ->first();
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'module_content', 'question_id', 'module_id')
            ->withPivot(['sort_order', 'required']);
    }
}
