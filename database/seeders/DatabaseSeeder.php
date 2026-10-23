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
            VideoCourseSeeder::class,
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
            SeemannschaftVideoSeeder::class,
            BinnenLessonQuestionSeeder::class,
            BinnenSegelnLessonQuestionSeeder::class,
            BinnenSegelnAddonLessonQuestionSeeder::class,
            FknSknLessonQuestionSeeder::class,
            AddSeeToBinVideoCourseSeeder::class,
            AddBinToSeeVideoCourseSeeder::class,
            NavigationTaskSeeder::class,
            ComboNavigationTaskSeeder::class,
            ExamRuleSetSeeder::class,
            ExamPaperSeeder::class,
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
