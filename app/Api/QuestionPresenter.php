<?php

namespace App\Api;

use App\Models\ContentAnswer;
use App\Models\ContentQuestionRevision;
use App\Models\MediaAsset;
use App\Support\QuestionMedia;

/**
 * Frage für die App -- ohne Lösung. Lösungen (richtige Antworten,
 * Erklärungen, Musterantworten) kommen nur über die Bewertung eines
 * Versuchs bzw. nach der Prüfungsabgabe.
 */
class QuestionPresenter
{
    /**
     * @param  ?string  $userId  Einfachauswahl in derselben, je Person
     *                           gemischten Reihenfolge wie im Web -- die Quelldaten führen die
     *                           richtige Antwort immer als erste (Schlüssel A).
     */
    public static function question(ContentQuestionRevision $revision, ?string $moduleId = null, ?string $userId = null): array
    {
        $revision->loadMissing('answers', 'media');
        $type = $revision->question_type ?: 'single_choice';
        // Mehrfachauswahl wie im Web in der gepflegten Reihenfolge.
        $answers = $type === 'multi_choice' ? $revision->answers->values() : $revision->answersInDisplayOrderFor($userId);

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
                ->map(fn (MediaAsset $m) => self::promptMedia($m))->values(),
            // key = Buchstabe der Anzeigeposition (nicht der Quell-Schlüssel, der die Lösung verriete).
            'answers' => $type === 'open_text' ? [] : $answers->values()->map(fn (ContentAnswer $a, int $i) => [
                'id' => $a->id,
                'key' => chr(65 + $i),
                'text' => $a->answer_text,
            ]),
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

    /**
     * Bild zur Frage. „url“ bleibt für bestehende App-Versionen erhalten:
     * öffentliche Medien wie bisher, geschützte (z. B. amtliche Bilder) als
     * signierte, kurzlebige Adresse für die angemeldete Person in der
     * gewählten Schule (QuestionMedia). Nie zwischenspeichern -- die
     * Adresse wird bei jeder Ausgabe neu erzeugt.
     */
    private static function promptMedia(MediaAsset $m): array
    {
        return Present::image(QuestionMedia::url($m), $m->alt_text, $m->mime_type) + self::download($m);
    }

    /**
     * Geprüfter Download des Bildes über die API (z. B. zum Offline-Speichern);
     * file_id/download_url nur für eigene, veröffentlichte Dateien.
     *
     * @return array{file_id: ?string, download_url: ?string}
     */
    private static function download(MediaAsset $m): array
    {
        $path = (string) $m->getRawOriginal('storage_path');
        $downloadable = $path !== '' && ! preg_match('#^https?://#i', $path)
            && $m->status === 'published' && in_array($m->rights_status, ['licensed', 'public'], true);

        return [
            'file_id' => $downloadable ? $m->id : null,
            'download_url' => $downloadable ? route('api.v1.files.download', ['fileId' => $m->id]) : null,
        ];
    }
}
