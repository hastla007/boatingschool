<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use Tests\TestCase;

/**
 * Bug-Review BB10: Der Fragetext trug an vier Stellen eine leere Klammer
 * "( )" an der Position eines nicht übernommenen Ton-Symbols. Siehe die
 * begleitende Migration 2024_02_01_000489_fix_bb10_empty_parentheses_sound_symbol_artifact.
 */
class BB10EmptyParenthesesSoundSymbolTest extends TestCase
{
    public function test_sbf_basis_0004_question_text_has_no_empty_parentheses(): void
    {
        $revision = ContentQuestion::where('content_id', 'SBF-BASIS-0004')->firstOrFail()->publishedRevision();

        $this->assertSame('Wie lang ist die Dauer eines kurzen Tons?', $revision->question_text);
    }

    public function test_sbf_basis_0005_question_text_has_no_empty_parentheses(): void
    {
        $revision = ContentQuestion::where('content_id', 'SBF-BASIS-0005')->firstOrFail()->publishedRevision();

        $this->assertSame('Wie lang ist die Dauer eines langen Tons?', $revision->question_text);
    }

    public function test_sbf_see_0116_question_text_has_no_empty_parentheses(): void
    {
        $revision = ContentQuestion::where('content_id', 'SBF-SEE-0116')->firstOrFail()->publishedRevision();

        $this->assertStringNotContainsString('( )', $revision->question_text);
        $this->assertStringContainsString('mit der Pfeife. Welches Fahrzeug', $revision->question_text);
    }

    public function test_sbf_see_0117_question_text_has_no_empty_parentheses(): void
    {
        $revision = ContentQuestion::where('content_id', 'SBF-SEE-0117')->firstOrFail()->publishedRevision();

        $this->assertStringNotContainsString('( )', $revision->question_text);
        $this->assertStringContainsString('mit der Pfeife. Welches Fahrzeug', $revision->question_text);
    }
}
