<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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
        'reviewed_by', 'reviewed_at', 'edited_by_superadmin',
    ];

    protected function casts(): array
    {
        return [
            'image_required' => 'boolean',
            'edited_by_superadmin' => 'boolean',
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

    /**
     * Bug-Review (Antwortreihenfolge bei Einfachauswahl-Fragen immer
     * identisch): answers() behält unverändert die sort_order aus dem
     * Import bei -- und im Quellkatalog steht die richtige Antwort fast
     * immer an erster Stelle (823 von 838 Einfachauswahl-Fragen haben
     * correct_answer=A in zentrale_fragenbasis.xlsx). Lernende merken sich
     * dadurch "immer die erste Antwort" statt des Inhalts, und die
     * Prüfungssimulation verliert jede Aussagekraft. Mischt die
     * Darstellungsreihenfolge pro Lernendem und Antwort deterministisch
     * (Hash aus Nutzer-ID + Antwort-ID) -- stabil bei jedem Neuladen und
     * während der laufenden Prüfung, aber unterschiedlich zwischen
     * Lernenden, sodass sich keine feste Reihenfolge einprägen lässt.
     * sort_order selbst (und damit answers()) bleibt unverändert, da es
     * u. a. vom Fragen-Editor und von hashRevision() für den
     * Import-Vergleich genutzt wird.
     */
    public function answersInDisplayOrderFor(?string $userId): Collection
    {
        if (! $userId) {
            return $this->answers;
        }

        return $this->answers
            ->sortBy(fn (ContentAnswer $answer) => md5($userId.'|'.$answer->id))
            ->values();
    }

    /** @return Collection<int, ContentAnswer> */
    public function correctAnswers(): Collection
    {
        return $this->answers->where('is_correct', true)->values();
    }
}
