<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bug-Review N4 (Seeder-Regression): ComboVideoCourseSeeder versuchte, die
 * drei SBF-Binnen-Kapitel aus $sbfBinnen->videoModules() zu spiegeln --
 * die aber ihrerseits erst von BinnenVideoCourseSeeder aus GENAU DIESEM
 * Kombikurs gespiegelt werden. Auf einer frischen Installation lief
 * ComboVideoCourseSeeder laut DatabaseSeeder zuerst, fand bei
 * $sbfBinnen also noch nichts vor und blieb komplett ohne Binnen-Teil --
 * und weil BinnenVideoCourseSeeder danach ebenfalls nichts zum Spiegeln
 * vorfand, hatte auch der eigenständige SBF-Binnen-Vollkurs gar keinen
 * Videokurs mehr (verifiziert per migrate:fresh --seed). Zusätzlich lief
 * VideoLessonQuestionSeeder bisher NACH ComboVideoCourseSeeder, dessen
 * Quiz-Spiegelung für den SBF-See-Teil kopierte deshalb auf einer
 * frischen Installation ebenfalls immer leere Daten.
 */
class ComboVideoCourseSeederTest extends TestCase
{
    public function test_the_combo_course_has_the_binnen_specific_chapters(): void
    {
        $combo = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE-BIN')->whereNull('tenant_id')->firstOrFail();

        $titles = $combo->videoModules()->pluck('title');

        foreach (['Grundlagen', 'Lichter, Zeichen und Töne', 'Bootsführung und Bordtechnik'] as $binnenChapter) {
            $this->assertTrue($titles->contains($binnenChapter), "Kombikurs fehlt das Binnen-Kapitel \"{$binnenChapter}\".");
        }
    }

    public function test_the_combo_course_has_quiz_questions_attached_to_its_sbf_see_lessons(): void
    {
        $combo = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE-BIN')->whereNull('tenant_id')->firstOrFail();

        $lessonIds = VideoLesson::whereIn('video_module_id', $combo->videoModules()->pluck('id'))->pluck('id');

        $this->assertGreaterThan(
            0,
            DB::table('video_lesson_question')->whereIn('video_lesson_id', $lessonIds)->count(),
            'Dem Kombikurs sind keine Quizfragen zugeordnet -- die Spiegelung aus SBF-SEE lief leer.'
        );
    }

    /**
     * Bug-Review R4-8: ComboVideoCourseSeeder legt die drei SBF-Binnen-
     * Kapitel für den Kombikurs NATIV an (eigene video_module_id/
     * video_lesson_id-Zeilen, keine Verweise auf den eigenständigen
     * Vollkurs). BinnenLessonQuestionSeeder verknüpfte bisher nur die
     * Lektionen des davon GESPIEGELTEN eigenständigen Vollkurses
     * (SBF-BIN-MOTOR) mit Quizfragen -- der Kombikurs selbst blieb bei
     * seinen eigenen, nativen Binnen-Kapiteln komplett ohne Quiz.
     */
    public function test_the_combo_course_has_quiz_questions_attached_to_its_binnen_chapters(): void
    {
        $combo = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE-BIN')->whereNull('tenant_id')->firstOrFail();

        $binnenModuleIds = $combo->videoModules()
            ->whereIn('title', ['Grundlagen', 'Lichter, Zeichen und Töne', 'Bootsführung und Bordtechnik'])
            ->pluck('id');

        $lessonIds = VideoLesson::whereIn('video_module_id', $binnenModuleIds)->pluck('id');

        $this->assertGreaterThan(
            0,
            DB::table('video_lesson_question')->whereIn('video_lesson_id', $lessonIds)->count(),
            'Den nativen Binnen-Kapiteln des Kombikurses sind keine Quizfragen zugeordnet.'
        );
    }

    public function test_the_standalone_binnen_course_mirrors_the_same_chapters_from_the_combo_course(): void
    {
        $binnen = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-BIN-MOTOR')->whereNull('tenant_id')->firstOrFail();

        $titles = $binnen->videoModules()->pluck('title');

        foreach (['Grundlagen', 'Lichter, Zeichen und Töne', 'Bootsführung und Bordtechnik'] as $binnenChapter) {
            $this->assertTrue($titles->contains($binnenChapter), "Eigenständiger SBF-Binnen-Vollkurs fehlt das Kapitel \"{$binnenChapter}\".");
        }
    }

    public function test_the_standalone_binnen_course_has_quiz_questions_attached(): void
    {
        $binnen = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-BIN-MOTOR')->whereNull('tenant_id')->firstOrFail();

        $lessonIds = VideoLesson::whereIn('video_module_id', $binnen->videoModules()->pluck('id'))->pluck('id');

        $this->assertGreaterThan(
            0,
            DB::table('video_lesson_question')->whereIn('video_lesson_id', $lessonIds)->count(),
            'Dem eigenständigen SBF-Binnen-Vollkurs sind keine Quizfragen zugeordnet.'
        );
    }

    /**
     * Pinnt die für die Spiegelung notwendige Reihenfolge in
     * DatabaseSeeder.php fest: VideoLessonQuestionSeeder muss vor
     * ComboVideoCourseSeeder laufen (dessen Quiz-Kopie für den SBF-See-
     * Teil braucht bereits zugeordnete Fragen), und ComboVideoCourseSeeder
     * muss vor BinnenVideoCourseSeeder laufen (das die Binnen-Kapitel aus
     * genau diesem Kombikurs spiegelt).
     */
    public function test_database_seeder_keeps_the_required_seeder_order(): void
    {
        $source = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));

        $position = fn (string $class) => strpos($source, $class.'::class');

        $videoCourseSeeder = $position('VideoCourseSeeder');
        $videoLessonQuestionSeeder = $position('VideoLessonQuestionSeeder');
        $comboVideoCourseSeeder = $position('ComboVideoCourseSeeder');
        $binnenVideoCourseSeeder = $position('BinnenVideoCourseSeeder');
        $binnenLessonQuestionSeeder = $position('BinnenLessonQuestionSeeder');

        $this->assertNotFalse($videoCourseSeeder);
        $this->assertNotFalse($videoLessonQuestionSeeder);
        $this->assertNotFalse($comboVideoCourseSeeder);
        $this->assertNotFalse($binnenVideoCourseSeeder);
        $this->assertNotFalse($binnenLessonQuestionSeeder);

        $this->assertTrue($videoCourseSeeder < $videoLessonQuestionSeeder, 'VideoLessonQuestionSeeder muss nach VideoCourseSeeder laufen.');
        $this->assertTrue($videoLessonQuestionSeeder < $comboVideoCourseSeeder, 'VideoLessonQuestionSeeder muss vor ComboVideoCourseSeeder laufen, sonst kopiert dessen Quiz-Spiegelung für SBF-See leere Daten.');
        $this->assertTrue($comboVideoCourseSeeder < $binnenVideoCourseSeeder, 'ComboVideoCourseSeeder muss vor BinnenVideoCourseSeeder laufen, sonst findet dieser keine Binnen-Kapitel zum Spiegeln vor.');
        $this->assertTrue($binnenVideoCourseSeeder < $binnenLessonQuestionSeeder, 'BinnenLessonQuestionSeeder muss nach BinnenVideoCourseSeeder laufen.');
    }
}
