<?php

namespace App\Api;

use App\Models\ContentAnswer;
use App\Models\ContentQuestionRevision;
use App\Models\MediaAsset;

/**
 * Frage für die App -- ohne Lösung. Lösungen (richtige Antworten,
 * Erklärungen, Musterantworten) kommen nur über die Bewertung eines
 * Versuchs bzw. nach der Prüfungsabgabe.
 */
class QuestionPresenter
{
    public static function question(ContentQuestionRevision $revision, ?string $moduleId = null): array
    {
        $revision->loadMissing('answers', 'media');
        $type = $revision->question_type ?: 'single_choice';

        return [
            'id' => $revision->question_id,
            'revision_id' => $revision->id,
            'official_number' => $revision->source_question_id,
            'text' => $revision->question_text,
            'question_type' => $type,
            'answer_selection' => match ($type) {
                'multi_choice' => 'multiple',
                'open_text' => 'self_assessment',
                default => 'single',
            },
            'prompt_media' => $revision->media->sortBy(fn ($m) => $m->pivot->sort_order ?? 0)
                ->map(fn (MediaAsset $m) => Present::image($m->storage_path, $m->alt_text, $m->mime_type))->values(),
            'answers' => $type === 'open_text' ? [] : $revision->answers->map(fn (ContentAnswer $a) => [
                'id' => $a->id,
                'key' => $a->answer_key,
                'text' => $a->answer_text,
            ])->values(),
            'topic' => $revision->smartmodus_kategorie ?: $revision->topic,
            'module_id' => $moduleId,
        ];
    }

    /** Lösung zu einer Revision (nur nach Bewertung bzw. Prüfungsabgabe). */
    public static function solution(ContentQuestionRevision $revision, ?bool $correct): array
    {
        $revision->loadMissing('answers');

        return [
            'correct_answer_ids' => $revision->question_type === 'open_text' ? [] : $revision->answers->where('is_correct', true)->pluck('id')->values(),
            'model_answer' => $revision->question_type === 'open_text' ? $revision->correctAnswer()?->answer_text : null,
            'explanation' => $correct === null ? ($revision->feedback_correct ?: null) : ($correct ? $revision->feedback_correct : $revision->feedback_incorrect),
        ];
    }
}
