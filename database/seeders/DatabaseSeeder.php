<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    /**
     * Reihenfolge folgt den fachlichen Abhängigkeiten: Module müssen vor dem
     * Content-Import existieren, Content vor Kursen/Demo-Lernaktivität.
     */
    public function run(): void
    {
        $this->call(ModuleSeeder::class);
        $this->call(PruefungsausschussSeeder::class);

        $this->command->info('Importiere zentrale Fragenbasis ...');
        Artisan::call('content:import', [], $this->command->getOutput());

        $this->command->info('Importiere Richtig-/Falsch-Feedback ...');
        Artisan::call('content:import-feedback', [], $this->command->getOutput());

        $this->call(FknSknContentSeeder::class);

        $this->call([
            CourseSeeder::class,
            // Bug-Review Betrieb B5: ExamPaperSeeder (hängt Bilder aus den
            // Prüfungsbögen, z. B. "Lichter-Frage-93-gif.png", an
            // content_question_revision -- siehe question_media) muss vor
            // VideoLessonQuestionSeeder laufen, da dessen LESSON_QUESTIONS
            // einige mehrdeutige Fragen über genau dieses Bild statt über
            // den Fragetext identifizieren. ExamPaperSeeder selbst braucht
            // nur ContentQuestion (Content-Import) und die Kursdefinition
            // -- unabhängig von jedem Video-/Lektions-Seeder -- und kann
            // deshalb gefahrlos vorgezogen werden, statt seinen gesamten
            // Exam*Seeder-Themenblock (unten) zu verschieben.
            ExamPaperSeeder::class,
            VideoCourseSeeder::class,
            // Bug-Review Betrieb B5: SeemannschaftVideoSeeder muss vor
            // VideoLessonQuestionSeeder laufen -- er legt für SBF See
            // zusätzliche Kapitel ("Berufsschifffahrt", "Leuchtfeuer und
            // Tonnen") und zusätzliche Lektionen innerhalb bestehender
            // Kapitel (u. a. "Ankern", "Der Wind", "Ausweichregeln für
            // Segelfahrzeuge") an, die LESSON_QUESTIONS in
            // VideoLessonQuestionSeeder braucht, um die amtlichen
            // Quizfragen anzuhängen. Lief VideoLessonQuestionSeeder davor
            // (vorherige Reihenfolge), fand es diese Kapitel/Lektionen noch
            // nicht vor und übersprang ihre Fragen kommentarlos (siehe
            // attachQuestions(): "if (! $lesson ...) return;") -- auf einer
            // frischen Installation wurden dadurch nur 27 statt der 78
            // amtlichen Quizfragen angehängt. Da VideoLessonQuestionSeeder
            // selbst idempotent ist (siehe exists()-Guard), "reparierte"
            // sich das beim NÄCHSTEN db:seed-Lauf scheinbar von selbst,
            // sobald die Lektionen aus dem ersten Lauf bereits existierten
            // -- frische Installation und erneuter Seed ergaben dadurch
            // unterschiedliche Inhalte.
            SeemannschaftVideoSeeder::class,
            // Bug-Review N4: VideoLessonQuestionSeeder muss vor
            // ComboVideoCourseSeeder laufen -- dessen Quiz-Spiegelung für
            // den SBF-See-Teil des Kombikurses kopiert die bereits an
            // SBF-SEE's eigenen Lektionen hängenden Fragen; auf einer
            // frischen Installation (vorher: Reihenfolge umgekehrt) waren
            // diese zum Zeitpunkt von ComboVideoCourseSeeder noch nie
            // befüllt, die Kopie lief also immer leer.
            VideoLessonQuestionSeeder::class,
            ComboVideoCourseSeeder::class,
            BinnenVideoCourseSeeder::class,
            BinnenSegelnVideoCourseSeeder::class,
            BinnenSegelnAddonVideoCourseSeeder::class,
            FknSknVideoCourseSeeder::class,
            BinnenKnotenVideoSeeder::class,
            BinnenLessonQuestionSeeder::class,
            BinnenSegelnLessonQuestionSeeder::class,
            BinnenSegelnAddonLessonQuestionSeeder::class,
            FknSknLessonQuestionSeeder::class,
            AddSeeToBinVideoCourseSeeder::class,
            AddBinToSeeVideoCourseSeeder::class,
            NavigationTaskSeeder::class,
            ComboNavigationTaskSeeder::class,
            ExamRuleSetSeeder::class,
            BinnenMotorExamPaperSeeder::class,
            AddSeeToBinExamPaperSeeder::class,
            AddBinToSeeExamPaperSeeder::class,
            ComboExamPaperSeeder::class,
            BinnenSegelnExamPaperSeeder::class,
            BinnenSegelnAddonExamPaperSeeder::class,
            SrcExamPaperSeeder::class,
            FknExamPaperSeeder::class,
            PraxisTrainerSeeder::class,
            ComboPraxisTrainerSeeder::class,
            PraxisFormTemplateSeeder::class,
        ]);

        // O3: alles ab hier erzeugt ausschließlich die beiden fiktiven
        // Demo-Bootsschulen ("mueller"/"hanse-kiel") samt Konten mit dem
        // Standardpasswort "password" -- unverzichtbar für lokale
        // Entwicklung/Tests, in einer echten Produktivumgebung aber
        // erratbare Zugangsdaten, die dort niemand angelegt haben will.
        if (app()->environment('local', 'testing')) {
            $this->call([
                TenantSeeder::class,
                UserAndEntitlementSeeder::class,
                AddonDemoSeeder::class,
                CoursePromoBannerDemoSeeder::class,
                TenantCourseLocationDemoSeeder::class,
                DemoLearningActivitySeeder::class,
                WillkommensboxDemoSeeder::class,
                PraxisAnmeldungDemoSeeder::class,
            ]);
        }
    }
}
