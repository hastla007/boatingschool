<?php

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use Illuminate\Database\Migrations\Migration;

/**
 * Bug-Review BB8: PDF-Fußzeile in einer Antwort von SBF-See-Frage 75.
 * Antwort C der Frage SBF-SEE-0075 ("Welche Sportboote sind von der
 * Fahrerlaubnispflicht ... ausgenommen?") trägt am Ende den Text
 * "Sportschifffahrt Sportbootführerscheine Fragenkatalog See Spezifische
 * Fragen See" -- die laufende Kopf-/Fußzeile der Quell-PDF, die beim
 * ursprünglichen Import des zentralen Fragenkatalogs (zentrale_
 * fragenbasis.xlsx) versehentlich mit in den Zellentext übernommen wurde.
 * Analog zum bereits etablierten Muster für Editor-Korrekturen (siehe
 * Migration add_edited_by_superadmin_to_content_question_revision sowie
 * Bug-Review "Seed überschreibt UBI-Korrekturen aus dem Editor") legt
 * diese Migration eine neue, als edited_by_superadmin markierte Revision
 * an, statt die bestehende Revision/Antwort direkt zu verändern -- ein
 * künftiger content:import-Lauf gegen eine unveränderte Excel-Quelle
 * erzeugt dadurch höchstens eine wartende Review-Revision statt die
 * Korrektur stillschweigend wieder zu verwerfen.
 */
return new class extends Migration
{
    public function up(): void
    {
        $question = ContentQuestion::where('content_id', 'SBF-SEE-0075')->first();

        if (! $question) {
            return;
        }

        $published = $question->revisions()->where('editorial_status', 'published')->orderByDesc('revision_no')->first();

        if (! $published) {
            return;
        }

        $answers = $published->answers()->orderBy('sort_order')->get();
        $footer = ' Sportschifffahrt Sportbootführerscheine Fragenkatalog See Spezifische Fragen See';
        $needsFix = $answers->contains(fn (ContentAnswer $a) => str_ends_with($a->answer_text, $footer));

        if (! $needsFix) {
            return;
        }

        $question->revisions()->where('editorial_status', 'published')->update(['editorial_status' => 'deprecated']);

        $latestRevisionNo = $question->revisions()->max('revision_no');

        $revision = ContentQuestionRevision::create([
            'question_id' => $question->id,
            'revision_no' => $latestRevisionNo + 1,
            'question_type' => $published->question_type,
            'question_text' => $published->question_text,
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

        foreach ($answers as $answer) {
            $text = str_ends_with($answer->answer_text, $footer)
                ? substr($answer->answer_text, 0, -strlen($footer))
                : $answer->answer_text;

            ContentAnswer::create([
                'revision_id' => $revision->id,
                'answer_key' => $answer->answer_key,
                'answer_text' => $text,
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
        $question = ContentQuestion::where('content_id', 'SBF-SEE-0075')->first();

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
