<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\ContentAnswer;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\Progress;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Kapselt die Learning Loop: Antwort einreichen -> serverseitig bewerten ->
 * unveränderlichen Attempt schreiben -> Progress atomar aktualisieren.
 * Es gibt bewusst kein update() auf Attempt-Ebene (Abnahmekriterium:
 * "Antworten werden serverseitig bewertet und jeder Versuch wird
 * unveränderlich gespeichert").
 */
class LearningService
{
    public function __construct(private MasteryCalculator $mastery)
    {
    }

    /**
     * @param  ?ContentAnswer  $selectedAnswer  Ausgewählte Antwort bei single_choice.
     * @param  ?\Illuminate\Support\Collection<int, ContentAnswer>  $selectedAnswers  Ausgewählte Antworten bei multi_choice (mehrere Checkboxen).
     * @param  ?bool  $selfAssessedKnown  Selbsteinschätzung bei open_text ("gewusst"/"nicht gewusst"), nicht aus is_correct abgeleitet.
     */
    public function recordAttempt(
        Tenant $tenant,
        User $user,
        ContentQuestionRevision $revision,
        ?ContentAnswer $selectedAnswer,
        string $context,
        ?CourseDefinition $course,
        ?int $responseTimeMs,
        ?\Illuminate\Support\Collection $selectedAnswers = null,
        ?bool $selfAssessedKnown = null,
    ): Attempt {
        return DB::transaction(function () use ($tenant, $user, $revision, $selectedAnswer, $context, $course, $responseTimeMs, $selectedAnswers, $selfAssessedKnown) {
            $type = $revision->question_type;
            $selectedAnswerIds = null;

            if ($type === 'multi_choice') {
                $correctIds = $revision->correctAnswers()->pluck('id')->sort()->values()->all();
                $selectedIds = ($selectedAnswers ?? collect())->pluck('id')->sort()->values()->all();
                $correct = $correctIds === $selectedIds;
                $selectedAnswerIds = ($selectedAnswers ?? collect())->pluck('id')->all();
                $effectiveSelectedAnswer = null;
            } elseif ($type === 'open_text') {
                $correct = (bool) $selfAssessedKnown;
                $effectiveSelectedAnswer = $revision->correctAnswer();
            } else {
                $correct = (bool) ($selectedAnswer?->is_correct);
                $effectiveSelectedAnswer = $selectedAnswer;
            }

            $attempt = Attempt::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'question_id' => $revision->question_id,
                'revision_id' => $revision->id,
                'course_id' => $course?->id,
                'selected_answer_id' => $effectiveSelectedAnswer?->id,
                'selected_answer_ids' => $selectedAnswerIds,
                'correct' => $correct,
                'context' => $context,
                'response_time_ms' => $responseTimeMs,
            ]);

            $progress = Progress::lockForUpdate()->firstOrNew([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'question_id' => $revision->question_id,
            ]);

            if (! $progress->exists) {
                $progress->fill([
                    'attempt_count' => 0, 'correct_count' => 0, 'incorrect_count' => 0,
                    'current_streak' => 0, 'mastery_score' => 0, 'learning_state' => 'neu',
                ]);
            }

            $this->mastery->apply($progress, $correct, now());
            $progress->save();

            return $attempt;
        });
    }
}
