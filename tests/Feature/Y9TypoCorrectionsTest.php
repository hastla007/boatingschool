<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use Tests\TestCase;

/**
 * Bug-Review (Y9: Tippfehler): drei konkrete Tippfehler in bereits
 * veröffentlichten Fragen, siehe die begleitende Migration
 * 2024_02_01_000482_fix_y9_typos_in_question_texts.
 *
 * - SKN-0053: fehlendes Fragezeichen ("...richtig." statt "...richtig?").
 * - SKN-0059: großgeschriebenes "Sie" liest sich wie eine förmliche
 *   Anrede des Lerners, bezieht sich aber auf "pyrotechnische Geschosse"
 *   (sie = sie, Plural) -- muss klein geschrieben werden.
 * - SBF-SEGEL-0274: "wärend" statt "während".
 *
 * Die im selben Bug-Report genannte "Leegerküste" ließ sich trotz
 * gründlicher Suche (question_text/feedback_correct/feedback_incorrect
 * aller Fragen, praxis_task, tip, navigation_task, alle CSV-/PHP-
 * Katalogquellen) an keiner Stelle im Code finden -- nicht erfunden.
 */
class Y9TypoCorrectionsTest extends TestCase
{
    public function test_skn_0053_question_mark_is_present(): void
    {
        $question = ContentQuestion::where('content_id', 'SKN-0053')->firstOrFail();
        $text = $question->publishedRevision()->question_text;

        $this->assertStringEndsWith('richtig?', $text);
        $this->assertStringNotContainsString('richtig.', $text);
    }

    public function test_skn_0059_uses_lowercase_sie_referring_back_to_geschosse(): void
    {
        $question = ContentQuestion::where('content_id', 'SKN-0059')->firstOrFail();
        $text = $question->publishedRevision()->question_text;

        $this->assertStringContainsString('wenn sie ins Wasser fallen?', $text);
        $this->assertStringNotContainsString('wenn Sie ins Wasser fallen?', $text);
    }

    public function test_sbf_segel_0274_spells_wahrend_correctly(): void
    {
        $question = ContentQuestion::where('content_id', 'SBF-SEGEL-0274')->firstOrFail();
        $text = $question->publishedRevision()->question_text;

        $this->assertStringContainsString('während der Fahrt', $text);
        $this->assertStringNotContainsString('wärend', $text);
    }
}
