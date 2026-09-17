<?php

namespace App\Services;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\Progress;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Transparente, deterministische Priorisierung laut api_spec.md:
 * "1. noch nie gesehen, 2. falsch/mehrfach falsch, 3. niedrige Mastery,
 * 4. next_review_at fällig, 5. schwaches Thema." Keine ML-Abhängigkeit.
 * apply() ist eine reine Funktion über bereits geladene Kandidaten, damit
 * sie ohne DB deterministisch getestet werden kann.
 */
class SmarttrainerService
{
    public function nextQuestion(Tenant $tenant, User $user, CourseDefinition $course, string $mode = 'smarttrainer', ?string $topic = null): ?array
    {
        $course->loadMissing(['modules.questions.revisions' => function ($query) {
            $query->where('editorial_status', 'published')->orderByDesc('revision_no');
        }]);

        $questions = $course->modules->flatMap(fn ($module) => $module->questions)->unique('id');

        if ($questions->isEmpty()) {
            return null;
        }

        $progressByQuestion = Progress::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->whereIn('question_id', $questions->pluck('id'))
            ->get()
            ->keyBy('question_id');

        $topicAccuracy = $this->topicAccuracy($progressByQuestion, $questions);

        $candidates = match ($mode) {
            'new' => $questions->filter(fn ($q) => ! $progressByQuestion->has($q->id)),
            'wrong' => $questions->filter(fn ($q) => $this->wasLastWrong($progressByQuestion->get($q->id))),
            'topic' => $topic ? $questions->filter(fn ($q) => $this->questionTopic($q) === $topic) : $questions,
            default => $questions,
        };

        if ($candidates->isEmpty()) {
            return null;
        }

        $ranked = $candidates
            ->map(function (ContentQuestion $question) use ($progressByQuestion, $topicAccuracy) {
                [$score, $reason] = $this->score($question, $progressByQuestion->get($question->id), $topicAccuracy);

                return ['question' => $question, 'score' => $score, 'reason' => $reason];
            })
            ->sortByDesc('score')
            ->values();

        $top = $ranked->first();

        return [
            'question' => $top['question'],
            'reason' => $top['reason'],
            'score' => $top['score'],
        ];
    }

    /** @return array{0: float, 1: string} */
    private function score(ContentQuestion $question, ?Progress $progress, Collection $topicAccuracy): array
    {
        if (! $progress || $progress->attempt_count === 0) {
            return [1000, 'Noch nie gesehen'];
        }

        if ($this->wasLastWrong($progress)) {
            return [800 + min($progress->incorrect_count, 10) * 10, 'Zuletzt falsch beantwortet'];
        }

        if ($progress->next_review_at && $progress->next_review_at->lte(now())) {
            return [500 + (1 - $progress->mastery_score) * 100, 'Wiederholung fällig'];
        }

        $topic = $this->questionTopic($question);
        $weak = $topic && ($topicAccuracy->get($topic) ?? 1) < 0.6;
        $base = (1 - $progress->mastery_score) * 300;

        if ($weak) {
            return [$base + 150, "Schwaches Thema: {$topic}"];
        }

        return [$base, 'Gefestigt, niedrige Priorität'];
    }

    private function wasLastWrong(?Progress $progress): bool
    {
        return $progress && $progress->attempt_count > 0 && $progress->current_streak === 0;
    }

    private function questionTopic(ContentQuestion $question): ?string
    {
        return $question->relationLoaded('revisions')
            ? $question->revisions->first()?->topic
            : $question->publishedRevision()?->topic;
    }

    private function topicAccuracy(Collection $progressByQuestion, Collection $questions): Collection
    {
        return $questions
            ->groupBy(fn ($q) => $this->questionTopic($q) ?? '—')
            ->map(function ($group) use ($progressByQuestion) {
                $rows = $group->map(fn ($q) => $progressByQuestion->get($q->id))->filter();
                $attempts = $rows->sum('attempt_count');
                $correct = $rows->sum('correct_count');

                return $attempts > 0 ? $correct / $attempts : 1;
            });
    }
}
