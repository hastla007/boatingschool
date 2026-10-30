<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use Tests\TestCase;

/**
 * Bug-Review BB8: PDF-Fußzeile in einer Antwort von SBF-See-Frage 75.
 * Antwort C der Frage SBF-SEE-0075 trug am Ende den Text
 * "Sportschifffahrt Sportbootführerscheine Fragenkatalog See Spezifische
 * Fragen See" -- die laufende Kopf-/Fußzeile der Quell-PDF, siehe die
 * begleitende Migration 2024_02_01_000487_fix_bb8_sbf_see_0075_pdf_footer_artifact.
 */
class BB8PdfFooterArtifactTest extends TestCase
{
    public function test_sbf_see_0075_answer_c_has_no_pdf_footer_artifact(): void
    {
        $question = ContentQuestion::where('content_id', 'SBF-SEE-0075')->firstOrFail();
        $revision = $question->publishedRevision();

        $this->assertTrue($revision->edited_by_superadmin);

        $answerC = $revision->answers()->where('answer_key', 'C')->firstOrFail();

        $this->assertStringEndsWith('Betriebsart S1 (Dauerbetrieb).', $answerC->answer_text);
        $this->assertStringNotContainsString('Sportschifffahrt Sportbootführerscheine', $answerC->answer_text);
    }
}
