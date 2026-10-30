<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoModule;
use Database\Seeders\BinnenLessonQuestionSeeder;
use Database\Seeders\BinnenSegelnAddonLessonQuestionSeeder;
use Database\Seeders\BinnenSegelnLessonQuestionSeeder;
use Database\Seeders\FknSknLessonQuestionSeeder;
use Database\Seeders\FunkLessonQuestionSeeder;
use Database\Seeders\SeemannschaftVideoSeeder;
use Database\Seeders\VideoLessonQuestionSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Bug-Review (Seeder-Idempotenz Videolektionen/-quiz): db:seed läuft auf
 * bestehenden Installationen erneut.
 *
 * - Die *LessonQuestionSeeder hängten Fragen an jede Lektion OHNE Fragen --
 *   ein vom Superadmin komplett geleertes Quiz kam zurück. Jetzt schützt
 *   video_lesson.quiz_seeded_at (Migration 2024_02_01_000500).
 * - SeemannschaftVideoSeeder erkannte seine Lektionen nur am Titel -- eine
 *   umbenannte oder gelöschte Lektion wurde neu angelegt. Jetzt schützt
 *   seed_marker (Migration 2024_02_01_000501).
 *
 * Alle Schreibzugriffe laufen in einer Transaktion der Owner-Verbindung,
 * die in tearDown() zurückgerollt wird.
 */
class VideoSeedersPreserveSuperadminEditsTest extends TestCase
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

    /** @return array<string, array{0: class-string<Seeder>, 1: string, 2?: string[]}> */
    public static function lessonQuestionSeeders(): array
    {
        return [
            'SBF See' => [VideoLessonQuestionSeeder::class, 'SBF-SEE'],
            'SBF Binnen' => [BinnenLessonQuestionSeeder::class, 'SBF-BIN-MOTOR'],
            // Nur die nativen Binnen-Kapitel -- die See-Kapitel kopiert ComboVideoCourseSeeder.
            'SBF Binnen Kombikurs' => [BinnenLessonQuestionSeeder::class, 'SBF-SEE-BIN', ['Grundlagen', 'Lichter, Zeichen und Töne', 'Bootsführung und Bordtechnik']],
            'SBF Binnen Segeln' => [BinnenSegelnLessonQuestionSeeder::class, 'SBF-BIN-SEGEL'],
            'Ergänzung Segeln' => [BinnenSegelnAddonLessonQuestionSeeder::class, 'ADD-BIN-SEGEL'],
            'FKN' => [FknSknLessonQuestionSeeder::class, 'FKN'],
            'SKN' => [FknSknLessonQuestionSeeder::class, 'SKN'],
            'SRC' => [FunkLessonQuestionSeeder::class, 'SRC'],
            'UBI' => [FunkLessonQuestionSeeder::class, 'UBI'],
        ];
    }

    /** @param  class-string<Seeder>  $seeder */
    #[DataProvider('lessonQuestionSeeders')]
    public function test_reseeding_does_not_refill_a_quiz_emptied_by_the_superadmin(string $seeder, string $courseCode, ?array $chapters = null): void
    {
        $lessons = $this->lessonsWithQuestions($courseCode, $chapters);
        $this->assertNotEmpty($lessons, "{$courseCode}: keine Lektion mit Quiz gefunden.");

        foreach ($lessons as $lesson) {
            $this->assertNotNull($lesson->quiz_seeded_at, "{$courseCode}: Lektion '{$lesson->title}' mit Quiz ist nicht als geseedet markiert (Backfill).");
            $lesson->questions()->detach();
        }

        (new $seeder)->run();

        foreach ($lessons as $lesson) {
            $this->assertSame(0, $lesson->questions()->count(), "{$courseCode}: Seeder hat das geleerte Quiz der Lektion '{$lesson->title}' wieder befüllt.");
        }

        // Gegenprobe: eine nie geseedete Lektion (ohne Markierung) bekommt
        // ihr Quiz weiterhin.
        $lessons[0]->forceFill(['quiz_seeded_at' => null])->save();
        (new $seeder)->run();

        $this->assertGreaterThan(0, $lessons[0]->questions()->count(), "{$courseCode}: unmarkierte Lektion '{$lessons[0]->title}' wurde nicht geseedet.");
        $this->assertNotNull($lessons[0]->fresh()->quiz_seeded_at);
    }

    public function test_a_lesson_with_questions_but_without_marker_is_marked_and_left_unchanged(): void
    {
        $lesson = $this->lessonsWithQuestions('SRC')[0];
        $keep = $lesson->questions()->pluck('content_question.id')->first();
        $lesson->questions()->sync([$keep => ['sort_order' => 1]]);
        $lesson->forceFill(['quiz_seeded_at' => null])->save();

        (new FunkLessonQuestionSeeder)->run();

        $this->assertSame([$keep], $lesson->questions()->pluck('content_question.id')->all());
        $this->assertNotNull($lesson->fresh()->quiz_seeded_at);
    }

    public function test_seemannschaft_seeder_does_not_recreate_renamed_or_deleted_lessons_and_chapters(): void
    {
        $sbfSee = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->firstOrFail();
        $lessonCount = fn () => VideoLesson::whereHas('module', fn ($q) => $q->where('course_id', $sbfSee->id))->count();
        $find = fn (string $chapter, string $title) => VideoLesson::whereHas('module', fn ($q) => $q->where('course_id', $sbfSee->id)->where('title', $chapter))
            ->where('title', $title)->first();

        $find('Seemannschaft', 'Ankern')->forceFill(['title' => 'Ankern und Ankermanöver'])->save();
        $find('Wetterkunde', 'Wetterkarten')->delete();
        VideoModule::where('course_id', $sbfSee->id)->where('title', 'Berufsschifffahrt')->firstOrFail()->forceFill(['title' => 'Berufsschifffahrt (umbenannt)'])->save();
        $before = $lessonCount();
        $chapters = VideoModule::where('course_id', $sbfSee->id)->count();

        (new SeemannschaftVideoSeeder)->run();

        $this->assertSame($before, $lessonCount(), 'SeemannschaftVideoSeeder hat umbenannte/gelöschte Lektionen erneut angelegt.');
        $this->assertSame($chapters, VideoModule::where('course_id', $sbfSee->id)->count(), 'SeemannschaftVideoSeeder hat ein umbenanntes Kapitel erneut angelegt.');
        $this->assertNull($find('Seemannschaft', 'Ankern'));
        $this->assertNull($find('Wetterkunde', 'Wetterkarten'));

        // Gegenprobe: ein (noch) nicht als geseedet vermerkter Eintrag --
        // etwa eine künftig in EXTRA_LESSONS ergänzte Lektion -- wird
        // weiterhin einmalig angelegt und dann vermerkt.
        DB::table('seed_marker')->where('key', SeemannschaftVideoSeeder::lessonKey('Wetterkunde', 'Wetterkarten'))->delete();
        (new SeemannschaftVideoSeeder)->run();
        (new SeemannschaftVideoSeeder)->run();

        $this->assertSame($before + 1, $lessonCount());
        $this->assertNotNull($find('Wetterkunde', 'Wetterkarten'));
        $this->assertTrue(DB::table('seed_marker')->where('key', SeemannschaftVideoSeeder::lessonKey('Wetterkunde', 'Wetterkarten'))->exists());
    }

    public function test_seed_markers_were_backfilled_for_the_existing_seemannschaft_lessons(): void
    {
        $extraLessons = (new \ReflectionClassConstant(SeemannschaftVideoSeeder::class, 'EXTRA_LESSONS'))->getValue();
        $newChapters = (new \ReflectionClassConstant(SeemannschaftVideoSeeder::class, 'NEW_CHAPTERS'))->getValue();

        foreach ($newChapters as [$chapter]) {
            $this->assertTrue(DB::table('seed_marker')->where('key', SeemannschaftVideoSeeder::chapterKey($chapter))->exists(), "Kapitel {$chapter}");
        }

        foreach ($extraLessons as $chapter => $lessons) {
            foreach ($lessons as [$title]) {
                $this->assertTrue(DB::table('seed_marker')->where('key', SeemannschaftVideoSeeder::lessonKey($chapter, $title))->exists(), "Lektion {$chapter}/{$title}");
            }
        }
    }

    /** @return VideoLesson[] */
    private function lessonsWithQuestions(string $courseCode, ?array $chapters = null): array
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', $courseCode)->whereNull('tenant_id')->firstOrFail();

        return VideoLesson::whereHas('module', fn ($q) => $q->where('course_id', $course->id)->when($chapters, fn ($q) => $q->whereIn('title', $chapters)))
            ->whereHas('questions')
            ->orderBy('id')
            ->limit(3)
            ->get()
            ->all();
    }
}
