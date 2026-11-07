<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\NavigationAnswer;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Antwort auf eine Teilfrage der Navigationsaufgabe in einer Prüfungssimulation. */
class ExamSessionNavigationAnswer extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'exam_session_navigation_answer';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'exam_session_id', 'navigation_question_id', 'position', 'answer', 'answered_at', 'self_assessed_known', 'correct',
    ];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime', 'self_assessed_known' => 'boolean', 'correct' => 'boolean'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(NavigationQuestion::class, 'navigation_question_id');
    }

    /** Lässt sich die Antwort automatisch gegen die Musterlösung prüfen? */
    public function isAutoCheckable(): bool
    {
        return (bool) NavigationAnswer::evaluate($this->question->answer_text, '')['auto'];
    }
}
