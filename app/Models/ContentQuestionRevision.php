<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

    /** @return Collection<int, ContentAnswer> */
    public function correctAnswers(): Collection
    {
        return $this->answers->where('is_correct', true)->values();
    }

    /**
     * Veröffentlicht diese Revision und setzt die bisher veröffentlichte
     * Fassung derselben Frage auf "deprecated".
     *
     * Bug-Review (Veröffentlichen einer Import-Revision verliert
     * Feedback/Bilder): ImportContentQuestions setzt auf einer neuen
     * "review"-Revision nie feedback_correct/feedback_incorrect und hängt
     * auch kein Bild an (beides sind reine Editor-Felder) -- ohne das hier
     * genau wie QuestionController::update() (Bug-Review B13) von der
     * bisher veröffentlichten Fassung zu übernehmen, würde das
     * Veröffentlichen einer solchen Import-Revision beides stillschweigend
     * löschen. $inheritEditorialContent = false überspringt das bewusst,
     * wenn die neue Fassung inhaltlich eine andere Frage sein kann (neuer
     * amtlicher Katalog, siehe UbiCatalogUpdateSeeder) -- dort würde eine
     * zur alten Antwort geschriebene Erklärung bzw. ein altes Bild falsch.
     */
    public function publish(bool $inheritEditorialContent = true): void
    {
        DB::transaction(function () use ($inheritEditorialContent) {
            $published = self::where('question_id', $this->question_id)
                ->where('editorial_status', 'published')
                ->first();

            if ($published) {
                if ($inheritEditorialContent) {
                    $this->update([
                        'feedback_correct' => $this->feedback_correct ?? $published->feedback_correct,
                        'feedback_incorrect' => $this->feedback_incorrect ?? $published->feedback_incorrect,
                    ]);

                    foreach ($published->media as $media) {
                        $this->media()->attach($media->id, ['role' => $media->pivot->role, 'sort_order' => $media->pivot->sort_order]);
                    }
                }

                $published->update(['editorial_status' => 'deprecated']);
            }

            $this->update(['editorial_status' => 'published']);
        });
    }
}
