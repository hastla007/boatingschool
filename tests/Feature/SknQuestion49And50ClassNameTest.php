<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use Tests\TestCase;

/**
 * Bug-Review (AA18: SKN-Fragen 49/50 nennen veraltete Klassen): siehe
 * skn_catalog.php und die begleitende Migration
 * 2024_02_01_000486_fix_skn_49_50_outdated_pyrotechnic_class_names.
 */
class SknQuestion49And50ClassNameTest extends TestCase
{
    public function test_skn_question_49_names_the_current_bam_p_class(): void
    {
        $question = ContentQuestion::where('content_id', 'SKN-0049')->firstOrFail();
        $answer = $question->revisions()->latest('revision_no')->first()->answers()->first();

        $this->assertStringContainsString('BAM-P 1', $answer->answer_text);
        $this->assertStringContainsString('BAM-P 2', $answer->answer_text);
        $this->assertStringNotContainsString('BAM-PT1', $answer->answer_text);
        $this->assertStringNotContainsString('BAM-PT2', $answer->answer_text);
    }

    public function test_skn_question_50_names_the_current_class_p(): void
    {
        $question = ContentQuestion::where('content_id', 'SKN-0050')->firstOrFail();
        $text = $question->revisions()->latest('revision_no')->first()->question_text;

        $this->assertStringContainsString('Klasse P', $text);
        $this->assertStringNotContainsString('Klasse T', $text);
    }
}
