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

    public function recordAttempt(
        Tenant $tenant,
        User $user,
        ContentQuestionRevision $revision,
        ?ContentAnswer $selectedAnswer,
        string $context,
        ?CourseDefinition $course,
        ?int $responseTimeMs,
    ): Attempt {
        return DB::transaction(function () use ($tenant, $user, $revision, $selectedAnswer, $context, $course, $responseTimeMs) {
            $correct = (bool) ($selectedAnswer?->is_correct);

            $attempt = Attempt::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'question_id' => $revision->question_id,
                'revision_id' => $revision->id,
                'course_id' => $course?->id,
                'selected_answer_id' => $selectedAnswer?->id,
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
