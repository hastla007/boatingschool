<?php

namespace Tests\Feature;

use App\Models\ContentQuestionRevision;
use App\Models\Module;
use Database\Seeders\ElwisQuestionImageSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * ELWIS-Bilder für SBF See, SBF Binnen, Binnen Segeln und die Basisfragen (siehe
 * ElwisQuestionImageSeeder und database/data/elwis_bilder.csv).
 */
class ElwisQuestionImageTest extends TestCase
{
    use InteractsWithTenants;

    public function test_every_binnen_and_basis_question_that_requires_an_image_has_one(): void
    {
        $this->onAdmin(function () {
            foreach (['SBF_BASIS', 'SBF_SEE', 'SBF_BINNEN', 'SBF_BINNEN_SEGELN'] as $code) {
                $questionIds = Module::where('code', $code)->firstOrFail()->questions()->pluck('content_question.id');
                $missing = ContentQuestionRevision::whereIn('question_id', $questionIds)
                    ->where('editorial_status', 'published')
                    ->where('image_required', true)
                    ->whereDoesntHave('media')
                    ->count();

                $this->assertSame(0, $missing, "{$code}: Fragen mit image_required ohne Bild.");
            }
        });
    }

    public function test_images_come_from_elwis_in_catalog_order(): void
    {
        $this->onAdmin(function () {
            $media = $this->publishedMedia('SBF_BASIS', '23');
            $this->assertSame(['elwis/sbf_basis_023_1.png', 'elwis/sbf_basis_023_2.png'], $media->pluck('asset_key')->all());
            $this->assertStringStartsWith('ELWIS', $media->first()->source);

            $this->assertSame(['elwis/sbf_binnen_155_1.png', 'elwis/sbf_binnen_155_2.png'], $this->publishedMedia('SBF_BINNEN', '155')->pluck('asset_key')->all());
            $this->assertSame(['elwis/sbf_binnen_segeln_295_1.png'], $this->publishedMedia('SBF_BINNEN_SEGELN', '295')->pluck('asset_key')->all());
        });
    }

    public function test_alt_text_does_not_reveal_the_correct_answer(): void
    {
        $this->onAdmin(function () {
            $revision = $this->publishedRevision('SBF_BINNEN', '183');
            $alt = $revision->media->first()->alt_text;

            $this->assertSame('Abbildung zur Frage', $alt);
            $this->assertStringNotContainsString($revision->correctAnswer()->answer_text, $alt);
        });
    }

    public function test_image_files_are_large_enough_and_present(): void
    {
        $handle = fopen(database_path('data/elwis_bilder.csv'), 'r');
        $header = fgetcsv($handle, 0, ';');
        $count = 0;

        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            $row = array_combine($header, $data);
            $path = database_path('data/elwis_bilder/'.$row['datei']);

            $this->assertFileExists($path);
            [$width, $height] = getimagesize($path);
            $this->assertGreaterThanOrEqual(64, min($width, $height), $row['datei']);
            $count++;
        }

        fclose($handle);
        $this->assertSame(163, $count);
    }

    public function test_no_sbf_question_still_uses_an_image_from_another_source(): void
    {
        $this->onAdmin(function () {
            $questionIds = Module::whereIn('code', ['SBF_BASIS', 'SBF_SEE', 'SBF_BINNEN', 'SBF_BINNEN_SEGELN'])->get()
                ->flatMap(fn ($module) => $module->questions()->pluck('content_question.id'));

            $foreign = DB::table('question_media')
                ->join('content_question_revision', 'content_question_revision.id', '=', 'question_media.revision_id')
                ->join('media_asset', 'media_asset.id', '=', 'question_media.media_asset_id')
                ->where('content_question_revision.editorial_status', 'published')
                ->whereIn('content_question_revision.question_id', $questionIds)
                ->where('media_asset.asset_key', 'not like', 'elwis/%')
                ->pluck('media_asset.asset_key');

            $this->assertSame([], $foreign->all());
            // SBF See 118-121 teilen sich dasselbe ELWIS-Schallsignal.
            $this->assertSame('elwis/sbf_see_pdfbild_041.png', $this->publishedMedia('SBF_SEE', '118')->first()->asset_key);
            $this->assertSame('elwis/sbf_see_pdfbild_041.png', $this->publishedMedia('SBF_SEE', '121')->first()->asset_key);
        });
    }

    public function test_see_279_answers_carry_the_morse_signals_from_the_catalog(): void
    {
        $this->onAdmin(function () {
            $revision = $this->publishedRevision('SBF_SEE', '279');
            $answers = $revision->answers->keyBy('answer_key');

            $this->assertFalse($revision->image_required);
            // Ohne die Morsezeichen waren die richtige Antwort A und die falsche D wortgleich.
            $this->assertNotSame($answers['A']->answer_text, $answers['D']->answer_text);
            // Geschützte Leerzeichen: das Zeichen darf nicht mitten im Signal umbrechen.
            $this->assertStringContainsString("dreimaliges Geben ·\u{00A0}·\u{00A0}·\u{00A0}–\u{00A0}–\u{00A0}–\u{00A0}·\u{00A0}·\u{00A0}· per Licht oder Ton", $answers['A']->answer_text);
            $this->assertStringContainsString("dreimaliges Geben –\u{00A0}–\u{00A0}–\u{00A0}·\u{00A0}·\u{00A0}·\u{00A0}–\u{00A0}–\u{00A0}– per Licht oder Ton", $answers['D']->answer_text);
            $this->assertTrue($answers['A']->is_correct);
        });
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->onAdmin(function () {
            $before = DB::table('question_media')->count();
            (new ElwisQuestionImageSeeder)->run();

            $this->assertSame($before, DB::table('question_media')->count());
            $this->assertCount(1, $this->publishedMedia('SBF_BASIS', '4'));
        });
    }

    private function publishedRevision(string $moduleCode, string $number): ContentQuestionRevision
    {
        return Module::where('code', $moduleCode)->firstOrFail()->questions()
            ->where('official_number', $number)->firstOrFail()->publishedRevision();
    }

    private function publishedMedia(string $moduleCode, string $number)
    {
        return $this->publishedRevision($moduleCode, $number)->media()->orderBy('question_media.sort_order')->get();
    }
}
