<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use Tests\TestCase;

/**
 * Bug-Review BB12: Tippfehler "Gewischtsschwerpunkt", "Wasersportler" und
 * "Notarlarm" aus dem ursprünglichen Import. Siehe die begleitende
 * Migration 2024_02_01_000491_fix_bb12_typos_gewichtsschwerpunkt_wassersportler_notalarm.
 */
class BB12TypoCorrectionsTest extends TestCase
{
    public function test_sbf_segel_0263_answer_a_says_gewichtsschwerpunkt(): void
    {
        $revision = ContentQuestion::where('content_id', 'SBF-SEGEL-0263')->firstOrFail()->publishedRevision();

        $answerA = $revision->answers()->where('answer_key', 'A')->firstOrFail();
        $this->assertStringContainsString('Gewichtsschwerpunkt', $answerA->answer_text);
        $this->assertStringNotContainsString('Gewischtsschwerpunkt', $answerA->answer_text);
    }

    public function test_fkn_0023_question_text_says_wassersportler(): void
    {
        $revision = ContentQuestion::where('content_id', 'FKN-0023')->firstOrFail()->publishedRevision();

        $this->assertStringContainsString('Wassersportler', $revision->question_text);
        $this->assertStringNotContainsString('Wasersportler', $revision->question_text);
    }

    public function test_src_0060_question_text_says_notalarm(): void
    {
        $revision = ContentQuestion::where('content_id', 'SRC-0060')->firstOrFail()->publishedRevision();

        $this->assertStringContainsString('Notalarm', $revision->question_text);
        $this->assertStringNotContainsString('Notarlarm', $revision->question_text);
    }
}
