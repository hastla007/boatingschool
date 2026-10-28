<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use Database\Seeders\ExamPaperSeeder;
use Illuminate\Database\QueryException;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Seeder nicht atomar): ExamPaperSeeder::run() verarbeitete die
 * CSV-Zeilen bisher ohne Transaktion -- ein Abbruch mitten im Durchlauf
 * (fehlerhafte Zeile, abgebrochener Deploy, DB-Verbindungsabbruch) hinterließ
 * dauerhaft nur die bis dahin angelegten Bögen (z. B. 7 statt 15), da die
 * Guard-Klausel (examPapers()->exists()) einen erneuten Seed-Lauf danach für
 * immer verhinderte. Dieser Test löst den Abbruch über eine absichtlich
 * doppelte Zeile aus, die den UNIQUE-Constraint (exam_paper_id, position)
 * verletzt, und prüft gegen einen eigenen, isolierten Testkurs (nicht die
 * echten SBF-SEE-Daten), dass dabei kein einziger Bogen zurückbleibt.
 */
class ExamPaperSeederAtomicityTest extends TestCase
{
    use InteractsWithTenants;

    private ?string $createdCourseId = null;

    private ?string $csvPath = null;

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            if ($this->createdCourseId) {
                CourseDefinition::withoutGlobalScopes()->where('id', $this->createdCourseId)->delete();
            }
        });

        if ($this->csvPath && file_exists($this->csvPath)) {
            unlink($this->csvPath);
        }

        parent::tearDown();
    }

    public function test_an_aborted_run_leaves_no_partial_exam_papers_behind(): void
    {
        $course = $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'code' => 'TEST-EPS-'.uniqid(),
            'name' => 'Test-Kurs für Seeder-Atomicität',
            'course_type' => 'full',
            'status' => 'published',
        ]));
        $this->createdCourseId = $course->id;

        $questions = $this->onAdmin(fn () => ContentQuestion::where('content_role', 'shared_basis')->whereNotNull('official_number')->limit(2)->get());
        $this->assertGreaterThanOrEqual(2, $questions->count(), 'Für diesen Test werden mindestens 2 vorhandene shared_basis-Fragen benötigt.');
        [$questionA, $questionB] = $questions;

        // Zeile 3 wiederholt bewusst Position 2 desselben Bogens -- das
        // verletzt den UNIQUE-Constraint (exam_paper_id, position) und
        // simuliert damit einen Abbruch mitten im Durchlauf (z. B. durch
        // eine fehlerhafte CSV-Zeile).
        $csv = "Prüfungsbogen;Position im Bogen;Fragenkatalog-Nr.;Tags;Bilddateien im ZIP\n"
            ."1;1;{$questionA->official_number};Basisfragen;\n"
            ."1;2;{$questionB->official_number};Basisfragen;\n"
            ."1;2;{$questionA->official_number};Basisfragen;\n";

        $this->csvPath = tempnam(sys_get_temp_dir(), 'eps-test-').'.csv';
        file_put_contents($this->csvPath, $csv);

        $seeder = new class extends ExamPaperSeeder
        {
            public ?CourseDefinition $testCourse = null;

            public ?string $testCsvPath = null;

            protected function resolveCourse(): ?CourseDefinition
            {
                return $this->testCourse;
            }

            protected function csvPath(): string
            {
                return $this->testCsvPath;
            }
        };
        $seeder->testCourse = $course;
        $seeder->testCsvPath = $this->csvPath;

        $this->expectException(QueryException::class);

        try {
            $this->onAdmin(fn () => $seeder->run());
        } finally {
            $this->onAdmin(fn () => $this->assertSame(0, ExamPaper::where('course_id', $course->id)->count()));
        }
    }
}
