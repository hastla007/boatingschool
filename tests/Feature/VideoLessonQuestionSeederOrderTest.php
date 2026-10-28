<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Bug-Review Betrieb B5: VideoLessonQuestionSeeder hängt die amtlichen
 * "Zu den Fragen"-Quizfragen an SBF Sees Video-Kapitel/-Lektionen -- die
 * aber teils erst von SeemannschaftVideoSeeder (zusätzliche Kapitel wie
 * "Berufsschifffahrt" und zusätzliche Lektionen wie "Ankern") bzw. von
 * ExamPaperSeeder (hängt einige mehrdeutige Fragen über ein Bild statt
 * über den Fragetext an content_question_revision) angelegt werden. Lief
 * VideoLessonQuestionSeeder (wie vor diesem Fix) VOR diesen beiden Seedern,
 * fand es die fehlenden Kapitel/Lektionen/Bilder noch nicht vor und
 * übersprang ihre Fragen kommentarlos (siehe
 * VideoLessonQuestionSeeder::run()/attachQuestions(): "if (! $lesson) return;")
 * -- auf einer frischen Installation wurden dadurch nur 27 statt der 78
 * amtlichen Quizfragen angehängt. Weil VideoLessonQuestionSeeder selbst
 * idempotent ist (exists()-Guard), "reparierte" sich das erst beim
 * NÄCHSTEN db:seed-Lauf von selbst -- frische Installation und erneuter
 * Seed ergaben dadurch unterschiedliche Inhalte (empirisch gegen eine
 * echte Postgres-Datenbank verifiziert: einzelner frischer Seed vorher 27,
 * danach 78; nach dem Fix bereits beim ersten Lauf 78).
 *
 * Ein voller DatabaseSeeder-Lauf ist in einem Test zu teuer (Content-Import,
 * dutzende Unterseeder, siehe DemoSeedingGuardTest) -- stattdessen wird hier
 * analog dazu direkt am Quelltext geprüft, dass die Reihenfolge stimmt.
 */
class VideoLessonQuestionSeederOrderTest extends TestCase
{
    public function test_seemannschaft_and_exam_paper_seeders_run_before_video_lesson_question_seeder(): void
    {
        $source = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));

        $videoLessonQuestionPos = strpos($source, 'VideoLessonQuestionSeeder::class');
        $seemannschaftPos = strpos($source, 'SeemannschaftVideoSeeder::class');
        $examPaperPos = strpos($source, 'ExamPaperSeeder::class');

        $this->assertNotFalse($videoLessonQuestionPos, 'VideoLessonQuestionSeeder::class nicht in DatabaseSeeder.php gefunden.');
        $this->assertNotFalse($seemannschaftPos, 'SeemannschaftVideoSeeder::class nicht in DatabaseSeeder.php gefunden.');
        $this->assertNotFalse($examPaperPos, 'ExamPaperSeeder::class nicht in DatabaseSeeder.php gefunden.');

        $this->assertLessThan(
            $videoLessonQuestionPos,
            $seemannschaftPos,
            'SeemannschaftVideoSeeder muss vor VideoLessonQuestionSeeder laufen -- sonst fehlen dessen zusätzliche SBF-See-Kapitel/-Lektionen beim Fragen-Attach.'
        );
        $this->assertLessThan(
            $videoLessonQuestionPos,
            $examPaperPos,
            'ExamPaperSeeder muss vor VideoLessonQuestionSeeder laufen -- sonst fehlen die per Bild identifizierten Fragen noch im Fragenkatalog.'
        );
    }
}
