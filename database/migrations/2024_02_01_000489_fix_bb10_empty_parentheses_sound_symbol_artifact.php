<?php

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use Illuminate\Database\Migrations\Migration;

/**
 * Bug-Review BB10: Beim ursprünglichen Import des zentralen Fragenkatalogs
 * trug der Fragetext an vier Stellen eine leere Klammer "( )" an der
 * Position, an der im Quell-PDF ein grafisches Ton-Symbol (Kurz-/Lang-Ton)
 * stand -- das Symbolglyph selbst ließ sich beim PDF-Textextrakt nicht
 * übernehmen, die umgebende Klammer blieb jedoch leer zurück. Zu jeder
 * dieser Fragen gehört bereits ein Bild, das das Schallsignal korrekt
 * zeigt (siehe question_media), sodass die leere Klammer keine zusätzliche
 * Information mehr trägt und ohne Risiko einer inhaltlich falschen
 * Ersatz-Vermutung entfernt werden kann.
 *
 * Analog zu BB8/BB9 legt diese Migration pro betroffener Frage eine neue,
 * als edited_by_superadmin markierte Revision an.
 */
return new class extends Migration
{
    private function fixes(): array
    {
        return [
            'SBF-BASIS-0004' => ['Wie lang ist die Dauer eines kurzen Tons ( )?' => 'Wie lang ist die Dauer eines kurzen Tons?'],
            'SBF-BASIS-0005' => ['Wie lang ist die Dauer eines langen Tons ( )?' => 'Wie lang ist die Dauer eines langen Tons?'],
            'SBF-SEE-0116' => [
                'Sie hören bei verminderter Sicht mindestens alle zwei Minuten einen langen Ton mit der Pfeife ( ). Welches Fahrzeug gibt dieses Signal?' => 'Sie hören bei verminderter Sicht mindestens alle zwei Minuten einen langen Ton mit der Pfeife. Welches Fahrzeug gibt dieses Signal?',
            ],
            'SBF-SEE-0117' => [
                'Sie hören bei verminderter Sicht mindestens alle zwei Minuten zwei aufeinander folgende lange Töne mit der Pfeife ( ). Welches Fahrzeug gibt dieses Signal?' => 'Sie hören bei verminderter Sicht mindestens alle zwei Minuten zwei aufeinander folgende lange Töne mit der Pfeife. Welches Fahrzeug gibt dieses Signal?',
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->fixes() as $contentId => $replacements) {
            $this->applyFix($contentId, $replacements);
        }
    }

    private function applyFix(string $contentId, array $replacements): void
    {
        $question = ContentQuestion::where('content_id', $contentId)->first();

        if (! $question) {
            return;
        }

        $published = $question->publishedRevision();

        if (! $published) {
            return;
        }

        $newQuestionText = strtr($published->question_text, $replacements);

        if ($newQuestionText === $published->question_text) {
            return;
        }

        $question->revisions()->where('editorial_status', 'published')->update(['editorial_status' => 'deprecated']);

        $latestRevisionNo = $question->revisions()->max('revision_no');

        $revision = ContentQuestionRevision::create([
            'question_id' => $question->id,
            'revision_no' => $latestRevisionNo + 1,
            'question_type' => $published->question_type,
            'question_text' => $newQuestionText,
            'feedback_correct' => $published->feedback_correct,
            'feedback_incorrect' => $published->feedback_incorrect,
            'topic' => $published->topic,
            'subtopic' => $published->subtopic,
            'smartmodus_kategorie' => $published->smartmodus_kategorie,
            'competency' => $published->competency,
            'image_required' => $published->image_required,
            'source_catalog' => $published->source_catalog,
            'source_version' => $published->source_version,
            'source_page' => $published->source_page,
            'source_question_id' => $published->source_question_id,
            'valid_from' => $published->valid_from,
            'valid_to' => $published->valid_to,
            'editorial_status' => 'published',
            'rights_status' => $published->rights_status,
            'edited_by_superadmin' => true,
        ]);

        foreach ($published->answers()->orderBy('sort_order')->get() as $answer) {
            ContentAnswer::create([
                'revision_id' => $revision->id,
                'answer_key' => $answer->answer_key,
                'answer_text' => $answer->answer_text,
                'is_correct' => $answer->is_correct,
                'sort_order' => $answer->sort_order,
            ]);
        }

        foreach ($published->media as $media) {
            $revision->media()->attach($media->id, ['role' => $media->pivot->role, 'sort_order' => $media->pivot->sort_order]);
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->fixes()) as $contentId) {
            $this->revertFix($contentId);
        }
    }

    private function revertFix(string $contentId): void
    {
        $question = ContentQuestion::where('content_id', $contentId)->first();

        if (! $question) {
            return;
        }

        $corrected = $question->revisions()
            ->where('editorial_status', 'published')
            ->where('edited_by_superadmin', true)
            ->orderByDesc('revision_no')
            ->first();

        $previous = $question->revisions()
            ->where('revision_no', '<', $corrected?->revision_no ?? 0)
            ->orderByDesc('revision_no')
            ->first();

        if (! $corrected || ! $previous) {
            return;
        }

        $corrected->delete();
        $previous->update(['editorial_status' => 'published']);
    }
};
