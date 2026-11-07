<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\NavigationAnswer;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Antwort auf eine Teilfrage der Navigationsaufgabe in einer Prüfungssimulation.
 * Frage und Musterlösung sind beim Start festgehalten (question_text,
 * answer_text) -- spätere Änderungen im Editor wirken nicht zurück.
 */
class ExamSessionNavigationAnswer extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'exam_session_navigation_answer';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'exam_session_id', 'navigation_question_id', 'position', 'question_number', 'question_text', 'answer_text',
        'answer', 'answered_at', 'self_assessed_known', 'correct',
    ];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime', 'self_assessed_known' => 'boolean', 'correct' => 'boolean'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(NavigationQuestion::class, 'navigation_question_id');
    }

    public function number(): int
    {
        return (int) ($this->question_number ?? $this->question?->question_number ?? $this->position);
    }

    public function questionText(): string
    {
        return (string) ($this->question_text ?? $this->question?->question_text);
    }

    public function modelAnswer(): string
    {
        return (string) ($this->answer_text ?? $this->question?->answer_text);
    }

    /** Lässt sich die Antwort automatisch gegen die Musterlösung prüfen? */
    public function isAutoCheckable(): bool
    {
        return (bool) NavigationAnswer::evaluate($this->modelAnswer(), '')['auto'];
    }

    /** Ergebnis der automatischen Prüfung (null, wenn nicht automatisch prüfbar). */
    public function autoResult(): ?bool
    {
        if (! $this->isAutoCheckable()) {
            return null;
        }
        $given = trim((string) $this->answer);

        return $given !== '' && (bool) NavigationAnswer::evaluate($this->modelAnswer(), $given)['correct'];
    }

    /** Eintragung in die Karte („Siehe Karte“): nichts einzutippen. */
    public function isChartDrawing(): bool
    {
        return (bool) preg_match('/^\s*siehe\s+karte/i', $this->modelAnswer());
    }
}
