<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use Database\Seeders\BinnenLessonQuestionSeeder;
use Database\Seeders\BinnenSegelnLessonQuestionSeeder;
use Database\Seeders\FunkLessonQuestionSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bug-Review (Quizfragen in falscher Lektion): Videoquiz-Fragen hingen an
 * Lektionen, zu deren Stoff sie nicht gehören (SBF-BIN-SEGEL "Mensch über
 * Bord" = Quickstopp, Segler-Ausweichregel unter "Berufsschifffahrt",
 * SBF Binnen "Notfallsignale" = gekenterte Jolle, SRC/UBI siehe
 * database/data/funk_video_quiz.php). Geprüft wird
 *
 * - der heutige Stand der (per Migration 2024_02_01_000502 korrigierten)
 *   Testdatenbank,
 * - die Seeder-Daten einer frischen Installation (Lektionen geleert und
 *   neu geseedet) und
 * - die Korrektur-Migration auf einem nachgestellten Altstand.
 *
 * Alle Schreibzugriffe laufen in einer Transaktion der Owner-Verbindung,
 * die in tearDown() zurückgerollt wird.
 */
class VideoQuizLessonPlacementTest extends TestCase
{
    private ?string $originalConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = config('database.default');
        config(['database.default' => 'pgsql_admin']);
        DB::connection('pgsql_admin')->beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::connection('pgsql_admin')->rollBack();
        config(['database.default' => $this->originalConnection]);

        parent::tearDown();
    }

    private const NOTFALLSIGNALE = ['SBF-BIN-0170', 'SBF-BIN-0250', 'SBF-BIN-0251', 'SBF-BIN-0210', 'SBF-BIN-0211'];

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string[]}> Kurs, Kapitel, Lektion, erwartete Fragen (Reihenfolge). */
    private static function expectedPlacements(): array
    {
        $expected = [
            'SEGEL MOB' => ['SBF-BIN-SEGEL', 'Seemannschaft auf Binnengewässern', 'Mensch über Bord', []],
            'SEGEL Tauwerk' => ['SBF-BIN-SEGEL', 'Seemannschaft auf Binnengewässern', 'Tauwerk und Ausrüstung', []],
            'SEGEL Ablegen' => ['SBF-BIN-SEGEL', 'Seemannschaft auf Binnengewässern', 'An- und Ablegen', ['SBF-BASIS-0035', 'SBF-BASIS-0040', 'SBF-BASIS-0048', 'SBF-BASIS-0061', 'SBF-BASIS-0060']],
            'SEGEL Segler' => ['SBF-BIN-SEGEL', 'Verkehrsregeln und Ausweichen', 'Segelfahrzeuge im Verkehr',
                ['SBF-BIN-0140', 'SBF-BIN-0143', 'SBF-BIN-0179', 'SBF-BIN-0184', 'SBF-BIN-0185', 'SBF-BIN-0186', 'SBF-BIN-0187', 'SBF-BIN-0188', 'SBF-BIN-0189']],
            'SEGEL Berufsschifffahrt' => ['SBF-BIN-SEGEL', 'Verkehrsregeln und Ausweichen', 'Berufsschifffahrt und Kleinfahrzeuge', ['SBF-BASIS-0034', 'SBF-BIN-0177', 'SBF-BIN-0181']],
            'SEGEL Segelteile' => ['SBF-BIN-SEGEL', 'Segeln: Boot, Rigg und Stabilität', 'Segelteile und Tauwerk',
                ['SBF-SEGEL-0267', 'SBF-SEGEL-0268', 'SBF-SEGEL-0269', 'SBF-SEGEL-0270', 'SBF-SEGEL-0271', 'SBF-SEGEL-0272', 'SBF-SEGEL-0273']],
            'SEGEL Trimm' => ['SBF-BIN-SEGEL', 'Segeln: Wind, Kurse und Trimm', 'Segeltrimm bei leichtem und starkem Wind',
                ['SBF-SEGEL-0286', 'SBF-SEGEL-0287', 'SBF-SEGEL-0288', 'SBF-SEGEL-0289', 'SBF-SEGEL-0290']],
            'SRC Sprechregeln' => ['SRC', 'Routineverkehr im Alltag', 'Sprechregeln und Buchstabiertafel', ['SRC-0115']],
            'SRC Rangfolge' => ['SRC', 'Dringlichkeit und Sicherheit – PAN PAN und SECURITE', 'Rangfolge im Seefunk', ['SRC-0085']],
            'SRC Prüfung' => ['SRC', 'Zulassung und Prüfungsvorbereitung', 'Ablauf der SRC-Prüfung', []],
            'SRC Recht' => ['SRC', 'Routineverkehr im Alltag', 'Rechtliche Regeln im Seefunk',
                ['SRC-0004', 'SRC-0005', 'SRC-0042', 'SRC-0043', 'SRC-0044', 'SRC-0084', 'SRC-0039', 'SRC-0086', 'SRC-0006']],
            'SRC MMSI' => ['SRC', 'Technik und Funkgerät', 'MMSI und der DSC-Controller',
                ['SRC-0053', 'SRC-0054', 'SRC-0055', 'SRC-0061', 'SRC-0064', 'SRC-0067', 'SRC-0062', 'SRC-0065', 'SRC-0066', 'SRC-0059']],
            'UBI Sprechregeln' => ['UBI', 'Notverkehr im Binnenfunk – MAYDAY', 'Sprechregeln, Buchstabiertafel und Anruf',
                ['UBI-0082', 'UBI-0096', 'UBI-0097', 'UBI-0098', 'UBI-0004', 'UBI-0104', 'UBI-0105']],
            'UBI Erlaubt' => ['UBI', 'Einführung in das UBI', 'Binnenfunk: wer funkt, was ist erlaubt?',
                ['UBI-0025', 'UBI-0026', 'UBI-0049', 'UBI-0028', 'UBI-0034', 'UBI-0031', 'UBI-0029', 'UBI-0033', 'UBI-0035', 'UBI-0037', 'UBI-0038', 'UBI-0018', 'UBI-0019', 'UBI-0020', 'UBI-0021']],
            'UBI Beenden' => ['UBI', 'Notverkehr im Binnenfunk – MAYDAY', 'Notverkehr offiziell beenden', ['UBI-0119']],
            'UBI Notmeldung' => ['UBI', 'Notverkehr im Binnenfunk – MAYDAY', 'Notfall auf dem Binnenrevier: die Notmeldung (MAYDAY)',
                ['UBI-0112', 'UBI-0111', 'UBI-0113', 'UBI-0114', 'UBI-0115', 'UBI-0120']],
            // Ergänzung/Kombikurs: nur die Fragen des Ergänzungskatalogs (UBI_ERG).
            'ADD-UBI Erlaubt' => ['ADD-UBI-TO-SRC', 'Einführung in das UBI', 'Binnenfunk: wer funkt, was ist erlaubt?', ['UBI-0025', 'UBI-0031', 'UBI-0029', 'UBI-0037', 'UBI-0018', 'UBI-0021']],
            'SRC-UBI Erlaubt' => ['SRC-UBI', 'Einführung in das UBI', 'Binnenfunk: wer funkt, was ist erlaubt?', ['UBI-0025', 'UBI-0031', 'UBI-0029', 'UBI-0037', 'UBI-0018', 'UBI-0021']],
            'SRC-UBI Beenden' => ['SRC-UBI', 'Notverkehr im Binnenfunk – MAYDAY', 'Notverkehr offiziell beenden', ['UBI-0119']],
            'SRC-UBI MMSI' => ['SRC-UBI', 'Technik und Funkgerät', 'MMSI und der DSC-Controller',
                ['SRC-0053', 'SRC-0054', 'SRC-0055', 'SRC-0061', 'SRC-0064', 'SRC-0067', 'SRC-0062', 'SRC-0065', 'SRC-0066', 'SRC-0059']],
        ];

        foreach (['SBF-BIN-MOTOR', 'SBF-SEE-BIN'] as $code) {
            $expected["{$code} Notfallsignale"] = [$code, 'Lichter, Zeichen und Töne', 'Notfallsignale', self::NOTFALLSIGNALE];
        }

        return $expected;
    }

    public function test_current_quiz_placement_matches_the_lesson_content(): void
    {
        foreach (self::expectedPlacements() as $label => [$code, $chapter, $lesson, $contentIds]) {
            $this->assertSame($contentIds, $this->contentIds($this->lesson($code, $chapter, $lesson)), $label);
        }

        // Ergänzung See -> Binnen spiegelt SBF-BIN-MOTOR.
        $this->assertSame(self::NOTFALLSIGNALE, $this->contentIds($this->lesson('ADD-SEE-TO-BIN', 'Lichter, Zeichen und Töne', 'Notfallsignale')));
    }

    public function test_seeders_produce_the_corrected_placement_on_fresh_lessons(): void
    {
        foreach (['SBF-BIN-SEGEL', 'SBF-BIN-MOTOR', 'SBF-SEE-BIN', 'SRC', 'UBI', 'ADD-UBI-TO-SRC', 'SRC-UBI'] as $code) {
            $this->resetCourseQuiz($code, $code === 'SBF-SEE-BIN' ? ['Grundlagen', 'Lichter, Zeichen und Töne', 'Bootsführung und Bordtechnik'] : null);
        }

        (new BinnenLessonQuestionSeeder)->run();
        (new BinnenSegelnLessonQuestionSeeder)->run();
        (new FunkLessonQuestionSeeder)->run();

        foreach (self::expectedPlacements() as $label => [$code, $chapter, $lesson, $contentIds]) {
            $this->assertSame($contentIds, $this->contentIds($this->lesson($code, $chapter, $lesson)), "frische Installation: {$label}");
        }

        // SBF-SEE-BIN ausgenommen: Basisfragen stehen dort bewusst sowohl im
        // See- als auch im Binnen-Teilkurs.
        foreach (['SBF-BIN-SEGEL', 'SBF-BIN-MOTOR', 'SRC', 'UBI', 'ADD-UBI-TO-SRC', 'SRC-UBI'] as $code) {
            $this->assertEachQuestionOncePerCourse($code);
        }
    }

    public function test_correction_migration_fixes_an_existing_installation_once(): void
    {
        // Altstand nachstellen (wie vor der Korrektur geseedet).
        foreach (['SBF-BIN-MOTOR', 'SBF-SEE-BIN', 'ADD-SEE-TO-BIN'] as $code) {
            $this->setQuiz($this->lesson($code, 'Lichter, Zeichen und Töne', 'Notfallsignale'), ['SBF-BIN-0190']);
        }
        $this->setQuiz($this->lesson('SBF-BIN-SEGEL', 'Seemannschaft auf Binnengewässern', 'Mensch über Bord'), ['SBF-BASIS-0042']);
        $this->setQuiz($this->lesson('SBF-BIN-SEGEL', 'Verkehrsregeln und Ausweichen', 'Segelfahrzeuge im Verkehr'), ['SBF-BIN-0140', 'SBF-BIN-0143', 'SBF-BIN-0179', 'SBF-BIN-0184']);
        $this->setQuiz($this->lesson('SBF-BIN-SEGEL', 'Verkehrsregeln und Ausweichen', 'Berufsschifffahrt und Kleinfahrzeuge'), ['SBF-BASIS-0034', 'SBF-BIN-0177', 'SBF-BIN-0181', 'SBF-BIN-0188']);
        $this->setQuiz($this->lesson('SRC', 'Technik und Funkgerät', 'MMSI und der DSC-Controller'), ['SRC-0053', 'SRC-0054', 'SRC-0055', 'SRC-0061', 'SRC-0064', 'SRC-0067', 'SRC-0062', 'SRC-0065', 'SRC-0066']);
        $this->setQuiz($this->lesson('SRC', 'Routineverkehr im Alltag', 'Sprechregeln und Buchstabiertafel'), ['SRC-0115', 'SRC-0059']);
        $this->setQuiz($this->lesson('UBI', 'Notverkehr im Binnenfunk – MAYDAY', 'Notfall auf dem Binnenrevier: die Notmeldung (MAYDAY)'), ['UBI-0112', 'UBI-0111', 'UBI-0113', 'UBI-0114', 'UBI-0115']);
        $this->setQuiz($this->lesson('UBI', 'Notverkehr im Binnenfunk – MAYDAY', 'Notverkehr offiziell beenden'), ['UBI-0120', 'UBI-0119']);
        // Vom Superadmin bereits gepflegt: SRC-0006 aus dem SRC-UBI-Kurs ganz entfernt.
        $this->setQuiz($this->lesson('SRC-UBI', 'Routineverkehr im Alltag', 'Rechtliche Regeln im Seefunk'), ['SRC-0004', 'SRC-0005', 'SRC-0042', 'SRC-0043', 'SRC-0044', 'SRC-0084', 'SRC-0039', 'SRC-0086']);

        $migration = require database_path('migrations/2024_02_01_000502_move_misfiled_video_quiz_questions.php');
        $migration->up();

        foreach (self::expectedPlacements() as $label => [$code, $chapter, $lesson, $contentIds]) {
            $this->assertSame($contentIds, $this->contentIds($this->lesson($code, $chapter, $lesson)), "Migration: {$label}");
        }
        $this->assertSame(self::NOTFALLSIGNALE, $this->contentIds($this->lesson('ADD-SEE-TO-BIN', 'Lichter, Zeichen und Töne', 'Notfallsignale')));
        $this->assertNotNull($this->lesson('SBF-BIN-SEGEL', 'Seemannschaft auf Binnengewässern', 'Mensch über Bord')->quiz_seeded_at, 'geleerte Lektion muss als geseedet markiert sein.');
        $this->assertNotContains('SRC-0006', $this->courseContentIds('SRC-UBI'), 'Vom Superadmin entfernte Frage darf die Migration nicht wieder anhängen.');

        // Idempotent: ein zweiter Lauf ändert nichts.
        $before = $this->courseContentIds('SBF-BIN-SEGEL');
        $migration->up();
        $this->assertSame($before, $this->courseContentIds('SBF-BIN-SEGEL'));
        $this->assertSame(self::NOTFALLSIGNALE, $this->contentIds($this->lesson('SBF-BIN-MOTOR', 'Lichter, Zeichen und Töne', 'Notfallsignale')));

        // Seeder füllen die geleerte Lektion danach nicht wieder.
        (new BinnenSegelnLessonQuestionSeeder)->run();
        (new FunkLessonQuestionSeeder)->run();
        $this->assertSame([], $this->contentIds($this->lesson('SBF-BIN-SEGEL', 'Seemannschaft auf Binnengewässern', 'Mensch über Bord')));
        $this->assertSame([], $this->contentIds($this->lesson('SRC', 'Zulassung und Prüfungsvorbereitung', 'Ablauf der SRC-Prüfung')));
    }

    public function test_funk_quiz_data_lists_every_catalog_question_exactly_once(): void
    {
        $quiz = include database_path('data/funk_video_quiz.php');

        foreach (['SRC' => 180, 'UBI' => 130] as $catalog => $count) {
            $ids = collect($quiz[$catalog])->flatten()->all();
            $this->assertCount($count, $ids, $catalog);
            $this->assertCount($count, array_unique($ids), "{$catalog}: Frage doppelt");
        }
    }

    // --- Hilfsfunktionen -------------------------------------------------

    private function lesson(string $code, string $chapter, string $title): VideoLesson
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', $code)->whereNull('tenant_id')->firstOrFail();

        return VideoLesson::whereHas('module', fn ($q) => $q->where('course_id', $course->id)->where('title', $chapter))
            ->where('title', $title)
            ->firstOrFail();
    }

    /** @return string[] */
    private function contentIds(VideoLesson $lesson): array
    {
        return $lesson->questions()->pluck('content_id')->all();
    }

    /** @param string[] $contentIds */
    private function setQuiz(VideoLesson $lesson, array $contentIds): void
    {
        $ids = ContentQuestion::whereIn('content_id', $contentIds)->pluck('id', 'content_id');
        $lesson->questions()->sync(collect($contentIds)->mapWithKeys(fn ($id, $i) => [$ids[$id] => ['sort_order' => $i + 1]])->all());
    }

    /** @param string[]|null $chapters */
    private function resetCourseQuiz(string $code, ?array $chapters = null): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', $code)->whereNull('tenant_id')->firstOrFail();
        $lessonIds = VideoLesson::whereHas('module', function ($q) use ($course, $chapters) {
            $q->where('course_id', $course->id);
            if ($chapters) {
                $q->whereIn('title', $chapters);
            }
        })->pluck('id');

        DB::table('video_lesson_question')->whereIn('video_lesson_id', $lessonIds)->delete();
        DB::table('video_lesson')->whereIn('id', $lessonIds)->update(['quiz_seeded_at' => null]);
    }

    /** @return string[] */
    private function courseContentIds(string $code): array
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', $code)->whereNull('tenant_id')->firstOrFail();

        return DB::table('video_lesson_question')
            ->join('video_lesson', 'video_lesson.id', '=', 'video_lesson_question.video_lesson_id')
            ->join('video_module', 'video_module.id', '=', 'video_lesson.video_module_id')
            ->join('content_question', 'content_question.id', '=', 'video_lesson_question.question_id')
            ->where('video_module.course_id', $course->id)
            ->orderBy('video_lesson.id')
            ->orderBy('video_lesson_question.sort_order')
            ->pluck('content_question.content_id')
            ->all();
    }

    private function assertEachQuestionOncePerCourse(string $code): void
    {
        $ids = $this->courseContentIds($code);
        $duplicates = array_keys(array_filter(array_count_values($ids), fn ($n) => $n > 1));

        $this->assertSame([], $duplicates, "{$code}: Quizfragen mehrfach im Kurs");
    }
}
