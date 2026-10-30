<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use Tests\TestCase;

/**
 * Bug-Review BB11: Doppelte Leerzeichen im Fragen-/Antworttext aus dem
 * ursprünglichen Import. Siehe die begleitende Migration
 * 2024_02_01_000490_fix_bb11_double_spaces_in_question_and_answer_text.
 */
class BB11DoubleSpaceCleanupTest extends TestCase
{
    private function assertNoDoubleSpaces(?string $text): void
    {
        if ($text === null) {
            return;
        }

        $this->assertDoesNotMatchRegularExpression('/[^\S\n]{2,}/u', $text);
    }

    public function test_no_published_question_has_double_spaces_left(): void
    {
        // Der ganze veröffentlichte Katalog statt einer festen Liste: die
        // Korrektur steht inzwischen in der Quelle (zentrale_fragenbasis.xlsx,
        // fragen_erklaerungen.csv), eine Neuinstallation muss sauber sein.
        $revisions = ContentQuestionRevision::where('editorial_status', 'published')->with('answers')->get();

        $this->assertGreaterThan(900, $revisions->count());

        foreach ($revisions as $revision) {
            $this->assertNoDoubleSpaces($revision->question_text);
            $this->assertNoDoubleSpaces($revision->feedback_correct);
            $this->assertNoDoubleSpaces($revision->feedback_incorrect);

            foreach ($revision->answers as $answer) {
                $this->assertNoDoubleSpaces($answer->answer_text);
            }
        }
    }

    public function test_ubi_0046_answer_b_wording_is_preserved_apart_from_the_space(): void
    {
        $revision = ContentQuestion::where('content_id', 'UBI-0046')->firstOrFail()->publishedRevision();

        $this->assertStringContainsString(
            'notfallbezogene Informationen (z. B. Notalarme) und',
            $revision->answers()->where('answer_key', 'B')->firstOrFail()->answer_text
        );
    }
}
