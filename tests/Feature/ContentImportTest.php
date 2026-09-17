<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use Illuminate\Support\Facades\Artisan;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Abnahmekriterien aus content_import_mapping.md: "Import ist wiederholbar
 * und idempotent", "Keine content_id wird unkontrolliert dupliziert",
 * "Content-Revisionen überschreiben historische Attempts nicht".
 */
class ContentImportTest extends TestCase
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

        // Reihenfolge wichtig: erst Tenant/Nutzer (kaskadiert Attempts weg),
        // erst danach die Frage selbst löschen -- sonst blockt die
        // Fremdschlüsselreferenz von attempt auf content_question.
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            $question = \App\Models\ContentQuestion::where('content_id', 'TEST-IMPORT-0001')->first();
            if ($question) {
                \Illuminate\Support\Facades\DB::table('module_content')->where('question_id', $question->id)->delete();
                $question->delete();
            }
        });

        parent::tearDown();
    }

    public function test_reimporting_unchanged_data_creates_no_duplicate_or_new_revision(): void
    {
        $this->existingModule('SBF_BASIS');
        $this->writeFixture('Ursprünglicher Fragetext?', 'A');

        $this->runImport();
        $this->runImport();

        $question = ContentQuestion::where('content_id', 'TEST-IMPORT-0001')->firstOrFail();
        $this->assertSame(1, $question->revisions()->count());
        $this->assertSame(1, ContentQuestion::where('content_id', 'TEST-IMPORT-0001')->count());
    }

    public function test_changed_content_creates_a_new_revision_without_touching_historical_attempts(): void
    {
        $this->existingModule('SBF_BASIS');
        $this->writeFixture('Ursprünglicher Fragetext?', 'A');
        $this->runImport();

        $question = ContentQuestion::where('content_id', 'TEST-IMPORT-0001')->firstOrFail();
        $revision1 = $question->revisions()->firstOrFail();

        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $answer = $revision1->answers()->firstOrFail();

        $attempt = $this->onAdmin(fn () => \App\Models\Attempt::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'question_id' => $question->id,
            'revision_id' => $revision1->id,
            'selected_answer_id' => $answer->id,
            'correct' => true,
            'context' => 'smarttrainer',
        ]));

        $this->writeFixture('Geänderter Fragetext?', 'B');
        $this->runImport();

        $this->assertSame(2, $question->revisions()->count());
        $attempt->refresh();
        $this->assertSame($revision1->id, $attempt->revision_id);
        $this->assertSame('Ursprünglicher Fragetext?', $revision1->fresh()->question_text);
    }

    private function writeFixture(string $questionText, string $correctAnswer): void
    {
        $this->fixturePath = storage_path('framework/testing/content-import-test.xlsx');
        @mkdir(dirname($this->fixturePath), recursive: true);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Central Question Pool');
        $sheet->fromArray(self::HEADER, null, 'A1');
        $sheet->fromArray([
            'TEST-IMPORT-0001', 999, 'Basis', 'See + Binnen', $questionText,
            'Antwort A', 'Antwort B', 'Antwort C', 'Antwort D', $correctAnswer,
            'Testthema', 'Testunterthema', 'Testkompetenz', false, 'SRC-TEST-0001', 'shared_basis',
        ], null, 'A2');

        (new Xlsx($spreadsheet))->save($this->fixturePath);
    }

    private function runImport(): void
    {
        $relative = 'storage/framework/testing/content-import-test.xlsx';
        Artisan::call('content:import', ['path' => $relative]);
    }
}
