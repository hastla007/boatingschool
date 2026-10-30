<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
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

    public function test_affected_questions_have_no_double_spaces_left(): void
    {
        $contentIds = [
            'SRC-0003',
            'UBI-0016', 'UBI-0046', 'UBI-0047', 'UBI-0052', 'UBI-0057',
            'UBI-0059', 'UBI-0063', 'UBI-0064', 'UBI-0067', 'UBI-0068',
            'UBI-0070', 'UBI-0074', 'UBI-0075', 'UBI-0079', 'UBI-0088',
            'UBI-0099', 'UBI-0101', 'UBI-0106', 'UBI-0115',
        ];

        foreach ($contentIds as $contentId) {
            $revision = ContentQuestion::where('content_id', $contentId)->firstOrFail()->publishedRevision();

            $this->assertTrue($revision->edited_by_superadmin, "{$contentId}: revision should be marked edited_by_superadmin");

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

    public function test_ubi_0057_question_text_wording_is_preserved_apart_from_the_space(): void
    {
        $revision = ContentQuestion::where('content_id', 'UBI-0057')->firstOrFail()->publishedRevision();

        $this->assertStringContainsString('Verkehrskreise des Binnenschifffahrtsfunks? [57]', $revision->question_text);
    }
}
