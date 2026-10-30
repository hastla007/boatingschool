<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review BB18: ImportContentQuestions::importRow() hängte eine Frage
 * beim Reimport bisher nur per $module->questions()->syncWithoutDetaching()
 * an das in der Excel-Zeile genannte Modul an -- eine frühere Zuordnung zu
 * einem ANDEREN Modul (etwa weil der Modulwert in der Quelle geändert
 * wurde) blieb dabei unberührt stehen. Eine Frage, die z. B. von "Basis"
 * nach "SRC" verschoben wird, tauchte dadurch in BEIDEN Modulen auf, statt
 * im alten zu verschwinden.
 */
class ImportModuleChangeRemovesOldModuleTest extends TestCase
{
    use InteractsWithTenants;

    private const HEADER = [
        'content_id', 'official_number', 'module', 'scope', 'question',
        'answer_a', 'answer_b', 'answer_c', 'answer_d', 'correct_answer',
        'topic', 'subtopic', 'competency', 'image_required', 'source_question_id', 'content_role',
    ];

    private ?string $fixturePath = null;

    protected function tearDown(): void
    {
        if ($this->fixturePath && file_exists($this->fixturePath)) {
            unlink($this->fixturePath);
        }

        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            $question = ContentQuestion::where('content_id', 'TEST-MODULE-SWITCH-0001')->first();
            if ($question) {
                DB::table('module_content')->where('question_id', $question->id)->delete();
                $question->delete();
            }
        });

        parent::tearDown();
    }

    public function test_reimporting_with_a_changed_module_column_moves_the_question_instead_of_duplicating_it(): void
    {
        $basis = $this->existingModule('SBF_BASIS');
        $src = $this->existingModule('SRC');

        $this->writeFixture('Basis');
        $this->runImport();

        $question = ContentQuestion::where('content_id', 'TEST-MODULE-SWITCH-0001')->firstOrFail();
        $this->assertSame([$basis->id], $question->modules()->pluck('module.id')->all());

        $this->writeFixture('SRC');
        $this->runImport();

        $question->refresh();
        $moduleIds = $question->modules()->pluck('module.id')->all();

        $this->assertSame([$src->id], $moduleIds, 'Die Frage muss nach einem Modulwechsel nur noch im neuen Modul stehen, nicht zusätzlich im alten.');
        $this->assertFalse($basis->questions()->where('question_id', $question->id)->exists());
    }

    public function test_a_reimport_keeps_modules_that_another_seeder_manages(): void
    {
        // Module außerhalb der Excel-Zuordnung (UBI_ERG trägt 79 UBI-Fragen
        // zusätzlich, siehe UbiErgaenzungSeeder) darf ein Reimport nicht
        // entfernen.
        $basis = $this->existingModule('SBF_BASIS');
        $ubiErg = $this->existingModule('UBI_ERG');

        $this->writeFixture('Basis');
        $this->runImport();

        $question = ContentQuestion::where('content_id', 'TEST-MODULE-SWITCH-0001')->firstOrFail();
        $this->onAdmin(fn () => DB::table('module_content')->insert(['module_id' => $ubiErg->id, 'question_id' => $question->id, 'required' => true]));

        $this->runImport();

        $moduleIds = $question->modules()->pluck('module.id')->sort()->values()->all();
        $this->assertSame(collect([$basis->id, $ubiErg->id])->sort()->values()->all(), $moduleIds);
    }

    private function writeFixture(string $module): void
    {
        $this->fixturePath = storage_path('framework/testing/module-switch-import-test.xlsx');
        @mkdir(dirname($this->fixturePath), recursive: true);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Central Question Pool');
        $sheet->fromArray(self::HEADER, null, 'A1');
        $sheet->fromArray([
            'TEST-MODULE-SWITCH-0001', 998, $module, 'See + Binnen', 'Testfrage für Modulwechsel?',
            'Antwort A', 'Antwort B', 'Antwort C', 'Antwort D', 'A',
            'Testthema', 'Testunterthema', 'Testkompetenz', false, 'SRC-TEST-0002', 'shared_basis',
        ], null, 'A2');

        (new Xlsx($spreadsheet))->save($this->fixturePath);
    }

    private function runImport(): void
    {
        Artisan::call('content:import', ['path' => 'storage/framework/testing/module-switch-import-test.xlsx']);
    }
}
