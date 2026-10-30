<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use Tests\TestCase;

/**
 * Bug-Review BB9: PDF-Zeilenumbrüche mitten in einem Wort wurden beim
 * ursprünglichen Import als Trennstrich plus Leerzeichen übernommen, z. B.
 * "Dringlichkeits- meldung" statt "Dringlichkeitsmeldung". Siehe die
 * begleitende Migration 2024_02_01_000488_fix_bb9_pdf_line_break_hyphenation_artifacts.
 */
class BB9PdfLineBreakHyphenationArtifactsTest extends TestCase
{
    public function test_sbf_bin_0151_answers_have_no_stray_space_in_the_tricolour_compound(): void
    {
        $revision = ContentQuestion::where('content_id', 'SBF-BIN-0151')->firstOrFail()->publishedRevision();

        $this->assertTrue($revision->edited_by_superadmin);

        $this->assertStringContainsString('rot-weiß-rote Seite gesperrt', $revision->answers()->where('answer_key', 'A')->firstOrFail()->answer_text);
        $this->assertStringContainsString('rot-weiß-rote Seite gesperrt', $revision->answers()->where('answer_key', 'B')->firstOrFail()->answer_text);
        $this->assertStringContainsString('rot-weiß-rote Seite gesperrt', $revision->answers()->where('answer_key', 'C')->firstOrFail()->answer_text);
        $this->assertStringContainsString('grün-weiß-grüne Seite gesperrt', $revision->answers()->where('answer_key', 'D')->firstOrFail()->answer_text);
    }

    public function test_src_0026_answer_d_has_no_split_funkausruestungspflichtigen(): void
    {
        $revision = ContentQuestion::where('content_id', 'SRC-0026')->firstOrFail()->publishedRevision();

        $this->assertTrue($revision->edited_by_superadmin);
        $this->assertStringContainsString(
            'nicht funkausrüstungspflichtigen Seeschiffen',
            $revision->answers()->where('answer_key', 'D')->firstOrFail()->answer_text
        );
    }

    public function test_src_0099_question_text_has_no_split_dringlichkeitsmeldung(): void
    {
        $revision = ContentQuestion::where('content_id', 'SRC-0099')->firstOrFail()->publishedRevision();

        $this->assertTrue($revision->edited_by_superadmin);
        $this->assertStringContainsString('ausgesendete Dringlichkeitsmeldung erledigt ist', $revision->question_text);
        $this->assertStringNotContainsString('Dringlichkeits- meldung', $revision->question_text);
    }

    public function test_src_0104_question_text_has_no_split_vorzugsweise(): void
    {
        $revision = ContentQuestion::where('content_id', 'SRC-0104')->firstOrFail()->publishedRevision();

        $this->assertTrue($revision->edited_by_superadmin);
        $this->assertStringContainsString('vorzugsweise verwendet', $revision->question_text);
        $this->assertStringNotContainsString('vorzugs- weise', $revision->question_text);
    }

    public function test_src_0156_answer_a_has_no_split_schiff_schiff_kanal(): void
    {
        $revision = ContentQuestion::where('content_id', 'SRC-0156')->firstOrFail()->publishedRevision();

        $this->assertTrue($revision->edited_by_superadmin);
        $this->assertStringContainsString(
            'Schiff-Schiff-Kanal',
            $revision->answers()->where('answer_key', 'A')->firstOrFail()->answer_text
        );
    }

    public function test_src_0165_question_text_has_no_split_cospas_sarsat(): void
    {
        $revision = ContentQuestion::where('content_id', 'SRC-0165')->firstOrFail()->publishedRevision();

        $this->assertTrue($revision->edited_by_superadmin);
        $this->assertStringContainsString('COSPAS-SARSAT-Satelliten-Seenotfunkbake', $revision->question_text);
    }

    public function test_src_0166_question_text_has_no_split_cospas_sarsat(): void
    {
        $revision = ContentQuestion::where('content_id', 'SRC-0166')->firstOrFail()->publishedRevision();

        $this->assertTrue($revision->edited_by_superadmin);
        $this->assertStringContainsString('COSPAS-SARSAT-Satelliten-Seenotfunkbake', $revision->question_text);
    }

    public function test_src_0180_answer_b_has_no_split_sar_fahrzeugen(): void
    {
        $revision = ContentQuestion::where('content_id', 'SRC-0180')->firstOrFail()->publishedRevision();

        $this->assertTrue($revision->edited_by_superadmin);
        $this->assertStringContainsString(
            'SAR-Fahrzeugen bzw. dem MRCC',
            $revision->answers()->where('answer_key', 'B')->firstOrFail()->answer_text
        );
    }

    public function test_ubi_0001_answer_a_has_no_split_sprechfunkdienst(): void
    {
        $revision = ContentQuestion::where('content_id', 'UBI-0001')->firstOrFail()->publishedRevision();

        $this->assertTrue($revision->edited_by_superadmin);
        $this->assertStringContainsString(
            'UKW/VHF-Sprechfunkdienst',
            $revision->answers()->where('answer_key', 'A')->firstOrFail()->answer_text
        );
    }

    public function test_ubi_0009_question_text_has_no_split_sprechfunkzeugnisses(): void
    {
        $revision = ContentQuestion::where('content_id', 'UBI-0009')->firstOrFail()->publishedRevision();

        $this->assertTrue($revision->edited_by_superadmin);
        $this->assertStringContainsString('UKW-Sprechfunkzeugnisses für den Binnenschifffahrtsfunk', $revision->question_text);
    }

    public function test_ubi_0103_question_text_has_no_split_schiffsfunkstellen(): void
    {
        $revision = ContentQuestion::where('content_id', 'UBI-0103')->firstOrFail()->publishedRevision();

        $this->assertTrue($revision->edited_by_superadmin);
        $this->assertStringContainsString('deutschen Schiffsfunkstellen und ausländischen', $revision->question_text);
    }
}
