<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ContentWorkflowSafetyTest extends TestCase
{
    use InteractsWithTenants;

    private array $contentIds = [];

    private array $files = [];

    protected function tearDown(): void
    {
        config(['database.default' => 'pgsql']);
        $this->onAdmin(function () {
            $ids = ContentQuestion::where(function ($q) {
                $q->whereIn('content_id', $this->contentIds)->orWhere('content_id', 'like', 'R8TEST-%');
            })->pluck('id');
            DB::table('module_content')->whereIn('question_id', $ids)->delete();
            DB::table('progress')->whereIn('question_id', $ids)->delete();
            ContentQuestion::whereIn('id', $ids)->delete();
            AuditLog::whereIn('actor_user_id', $this->createdUserIds)->delete();
        });
        foreach ($this->files as $f) {
            @unlink($f);
        }
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function question(string $feedback = 'Alte Erklärung', bool $editedBySuperadmin = false): ContentQuestion
    {
        return $this->onAdmin(function () use ($feedback, $editedBySuperadmin) {
            $q = ContentQuestion::create(['content_id' => 'R8TEST-'.uniqid(), 'language' => 'de-DE', 'active' => true]);
            $this->contentIds[] = $q->content_id;
            $r = ContentQuestionRevision::create([
                'question_id' => $q->id, 'revision_no' => 1, 'question_type' => 'single_choice',
                'question_text' => 'Was bedeutet ein gelbes Licht?', 'feedback_correct' => $feedback, 'feedback_incorrect' => 'Falsch-Erklärung',
                'editorial_status' => 'published', 'rights_status' => 'licensed', 'edited_by_superadmin' => $editedBySuperadmin,
            ]);
            foreach (['A', 'B', 'C', 'D'] as $i => $k) {
                ContentAnswer::create(['revision_id' => $r->id, 'answer_key' => $k, 'answer_text' => "Antwort $k", 'is_correct' => $k === 'A', 'sort_order' => $i + 1]);
            }

            return $q;
        });
    }

    /** Superadmin leert die (falsche) Erklärung bewusst -- der nächste Feedback-Import (db:seed) stellt sie wieder her. */
    public function test_feedback_import_does_not_restore_an_explanation_the_superadmin_cleared(): void
    {
        $superadmin = $this->createSuperAdmin();
        $q = $this->question('Falsche Erklärung aus der CSV');

        $this->actingAs($superadmin)->patch("/superadmin/questions/{$q->id}", [
            'question_type' => 'single_choice', 'question_text' => 'Was bedeutet ein gelbes Licht?',
            'feedback_correct' => '', 'feedback_incorrect' => '',
            'answers' => ['A' => 'Antwort A', 'B' => 'Antwort B', 'C' => 'Antwort C', 'D' => 'Antwort D'], 'correct' => 'A',
        ])->assertSessionHasNoErrors();
        $this->assertNull($this->onAdmin(fn () => $q->publishedRevision()->feedback_correct));

        $csv = storage_path('app/r8-feedback-'.uniqid().'.csv');
        $this->files[] = $csv;
        file_put_contents($csv, "content_id;feedback_correct;feedback_incorrect\n{$q->content_id};Richtig. Falsche Erklärung aus der CSV;Falsch. Falsch-Erklärung\n");
        Artisan::call('content:import-feedback', ['path' => ltrim(str_replace(base_path(), '', $csv), '/')]);
        config(['database.default' => 'pgsql']);

        $published = $this->onAdmin(fn () => $q->publishedRevision());
        $this->assertNull($published->feedback_correct, 'gelöschte Erklärung bleibt gelöscht');
        $this->assertTrue($published->edited_by_superadmin);
    }

    /** Eine Leerzeile in der CSV (z. B. am Ende) wirft ValueError in array_combine -> HTTP 500, kein Bericht, kein Audit. */
    public function test_feedback_csv_with_a_blank_line_is_imported(): void
    {
        $superadmin = $this->createSuperAdmin();
        $q = $this->question();
        $file = UploadedFile::fake()->createWithContent('erklaerungen.csv',
            "content_id;feedback_correct;feedback_incorrect\n{$q->content_id};Richtig. Neu;Falsch. Neu\n\n");

        $response = $this->actingAs($superadmin)->post('/superadmin/content-import/feedback', ['file' => $file]);
        config(['database.default' => 'pgsql']);
        $response->assertRedirect();
        $this->assertSame(1, $this->onAdmin(fn () => AuditLog::where('actor_user_id', $superadmin->id)->where('action', 'superadmin.content_import')->count()));
    }

    /** Ein DB-Fehler in Zeile 3 bricht den Upload mit 500 ab; Zeile 2 ist bereits live, Zeile 4 fehlt, kein Bericht/Audit. */
    public function test_excel_import_failure_is_reported_and_audited(): void
    {
        $superadmin = $this->createSuperAdmin();
        $id1 = 'R8TEST-X1-'.uniqid();
        $id2 = 'R8TEST-X2-'.uniqid();
        $id3 = 'R8TEST-X3-'.uniqid();
        $sheet = new Spreadsheet;
        $ws = $sheet->getActiveSheet()->setTitle('Central Question Pool');
        $ws->fromArray([
            ['content_id', 'official_number', 'content_role', 'module', 'question', 'answer_a', 'answer_b', 'answer_c', 'answer_d', 'correct_answer'],
            [$id1, '9001', '', 'Basis', 'R8 Frage eins?', 'a', 'b', 'c', 'd', 'A'],
            [$id2, str_repeat('9', 60), '', 'Basis', 'R8 Frage zwei?', 'a', 'b', 'c', 'd', 'A'],
            [$id3, '9003', '', 'Basis', 'R8 Frage drei?', 'a', 'b', 'c', 'd', 'A'],
        ]);
        $path = sys_get_temp_dir().'/r8-'.uniqid().'.xlsx';
        (new Xlsx($sheet))->save($path);
        $this->files[] = $path;

        $response = $this->actingAs($superadmin)->post('/superadmin/content-import/questions', [
            'file' => new UploadedFile($path, 'fragen.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ]);
        config(['database.default' => 'pgsql']);

        $response->assertRedirect();
        $report = session('import_report');
        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('Import abgebrochen', implode("\n", $report['lines']));
        $this->assertSame(1, $this->onAdmin(fn () => AuditLog::where('actor_user_id', $superadmin->id)->where('action', 'superadmin.content_import')->count()));
    }

    /** Import-Revision ändert die richtige Antwort; Freigabe erbt die alte Erklärung, Diff zeigt "Erklärung entfernt". */
    public function test_publishing_with_a_new_correct_answer_drops_the_old_explanation(): void
    {
        $superadmin = $this->createSuperAdmin();
        $q = $this->question('A ist richtig, weil gelb Vorsicht bedeutet.');
        $review = $this->onAdmin(function () use ($q) {
            $r = ContentQuestionRevision::create([
                'question_id' => $q->id, 'revision_no' => 2, 'question_type' => 'single_choice',
                'question_text' => 'Was bedeutet ein gelbes Licht?', 'editorial_status' => 'review', 'rights_status' => 'review',
            ]);
            foreach (['A', 'B', 'C', 'D'] as $i => $k) {
                ContentAnswer::create(['revision_id' => $r->id, 'answer_key' => $k, 'answer_text' => "Antwort $k", 'is_correct' => $k === 'B', 'sort_order' => $i + 1]);
            }

            return $r;
        });

        $this->actingAs($superadmin)->get("/superadmin/questions/{$q->id}/edit")->assertOk()
            ->assertSee('die bisherige Erklärung wird nicht übernommen');

        $this->actingAs($superadmin)->post("/superadmin/question-revisions/{$review->id}/publish")->assertSessionHasNoErrors();
        $published = $this->onAdmin(fn () => $q->publishedRevision()->load('answers'));
        $this->assertSame($review->id, $published->id);
        $this->assertSame('B', $published->correctAnswer()->answer_key);
        $this->assertNull($published->feedback_correct);
    }

    /** Kein Audit für Fragen-, Kurs- und Lektionsänderungen. */
    public function test_question_and_course_edits_are_audited(): void
    {
        $superadmin = $this->createSuperAdmin();
        $q = $this->question();
        $this->actingAs($superadmin)->patch("/superadmin/questions/{$q->id}", [
            'question_type' => 'single_choice', 'question_text' => 'Geänderter Text?',
            'answers' => ['A' => 'Antwort A', 'B' => 'Antwort B', 'C' => 'Antwort C', 'D' => 'Antwort D'], 'correct' => 'B',
        ])->assertSessionHasNoErrors();
        $this->actingAs($superadmin)->post("/superadmin/questions/{$q->id}/toggle-active")->assertRedirect();

        $course = $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->create(['tenant_id' => null, 'code' => 'R8TEST-C-'.uniqid(), 'name' => 'R8', 'course_type' => 'full', 'status' => 'published', 'site_enabled' => true]));
        $this->actingAs($superadmin)->patch("/superadmin/courses/{$course->id}", ['name' => 'R8', 'course_type' => 'full', 'status' => 'archived'])->assertSessionHasNoErrors();

        $this->assertGreaterThanOrEqual(3, $this->onAdmin(fn () => AuditLog::where('actor_user_id', $superadmin->id)->count()));
        $this->onAdmin(fn () => $course->delete());
    }
}
