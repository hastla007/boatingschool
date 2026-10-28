<?php

namespace Tests\Feature;

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\MediaAsset;
use App\Models\Module;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Hoch): 58 veröffentlichte Fragen (vor allem aus den
 * "Binnen"-Prüfungsbogen-CSVs, die strukturell keine Bilddateien-Spalte
 * besitzen) haben image_required=true ohne angehängtes Bild -- eine
 * echte Datenlücke in den Quelldateien, kein Code-Fehler (siehe
 * AuditMissingRequiredImages-Docblock). Dieser Befehl macht die Lücke
 * auffindbar statt sie nur per Ad-hoc-SQL entdecken zu können.
 */
class AuditMissingRequiredImagesTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdModuleIds = [];

    private array $createdQuestionIds = [];

    private array $createdMediaAssetIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdModuleIds as $id) {
                Module::where('id', $id)->delete();
            }
            foreach ($this->createdQuestionIds as $id) {
                ContentQuestion::where('id', $id)->delete();
            }
            foreach ($this->createdMediaAssetIds as $id) {
                MediaAsset::where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    public function test_it_reports_a_published_question_with_image_required_and_no_media(): void
    {
        $this->onAdmin(function () {
            $module = Module::create(['code' => 'TEST-AUDIT-IMG-'.uniqid(), 'name' => 'Test Modul Audit', 'version' => '1', 'status' => 'published']);
            $this->createdModuleIds[] = $module->id;

            $question = ContentQuestion::create(['content_id' => 'TEST-AUDIT-IMG-Q-'.uniqid(), 'official_number' => 'TEST-9999', 'language' => 'de-DE', 'active' => true]);
            $this->createdQuestionIds[] = $question->id;
            $module->questions()->attach($question->id, ['required' => true]);

            ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 1,
                'question_type' => 'single_choice',
                'question_text' => 'Welches Zeichen zeigt die abgebildete Tonne?',
                'image_required' => true,
                'editorial_status' => 'published',
                'rights_status' => 'licensed',
            ]);
        });

        Artisan::call('content:audit-missing-images');
        $output = Artisan::output();

        $this->assertStringContainsString('TEST-9999', $output);
    }

    public function test_it_does_not_report_a_question_that_already_has_its_required_image(): void
    {
        [$officialNumber, $output] = $this->onAdmin(function () {
            $module = Module::create(['code' => 'TEST-AUDIT-IMG-OK-'.uniqid(), 'name' => 'Test Modul Audit OK', 'version' => '1', 'status' => 'published']);
            $this->createdModuleIds[] = $module->id;

            $officialNumber = 'TEST-8888';
            $question = ContentQuestion::create(['content_id' => 'TEST-AUDIT-IMG-OK-Q-'.uniqid(), 'official_number' => $officialNumber, 'language' => 'de-DE', 'active' => true]);
            $this->createdQuestionIds[] = $question->id;
            $module->questions()->attach($question->id, ['required' => true]);

            $revision = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 1,
                'question_type' => 'single_choice',
                'question_text' => 'Welches Zeichen zeigt die abgebildete Tonne?',
                'image_required' => true,
                'editorial_status' => 'published',
                'rights_status' => 'licensed',
            ]);

            ContentAnswer::create(['revision_id' => $revision->id, 'answer_key' => 'A', 'answer_text' => 'A', 'is_correct' => true, 'sort_order' => 1]);

            $media = MediaAsset::create(['asset_key' => 'test-audit-img-'.uniqid(), 'media_type' => 'image', 'storage_path' => '/images/test.png']);
            $this->createdMediaAssetIds[] = $media->id;
            $revision->media()->attach($media->id, ['role' => 'question', 'sort_order' => 1]);

            return [$officialNumber, null];
        });

        Artisan::call('content:audit-missing-images');

        $this->assertStringNotContainsString($officialNumber, Artisan::output());
    }
}
