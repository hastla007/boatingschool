<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use Tests\TestCase;

/**
 * Bug-Review (SKN-Frage 8 fälschlich "Seenot-Handfackel"): siehe
 * skn_catalog.php und die begleitende Migration
 * 2024_02_01_000477_fix_skn_question_8_rauchsignal_wording.
 */
class SknQuestion8WordingTest extends TestCase
{
    public function test_skn_question_8_refers_to_rauchsignal_not_handfackel(): void
    {
        $question = ContentQuestion::where('content_id', 'SKN-0008')->firstOrFail();
        $text = $question->revisions()->latest('revision_no')->first()->question_text;

        $this->assertStringContainsString('Seenot-Rauchsignals', $text);
        $this->assertStringNotContainsString('Seenot-Handfackel', $text);
    }
}
