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

        $this->command->info('Importiere zentrale Fragenbasis ...');
        Artisan::call('content:import', [], $this->command->getOutput());

        $this->command->info('Importiere Richtig-/Falsch-Feedback ...');
        Artisan::call('content:import-feedback', [], $this->command->getOutput());

        $this->call([
            CourseSeeder::class,
            VideoCourseSeeder::class,
            ComboVideoCourseSeeder::class,
            BinnenVideoCourseSeeder::class,
            BinnenKnotenVideoSeeder::class,
            SeemannschaftVideoSeeder::class,
            VideoLessonQuestionSeeder::class,
            BinnenLessonQuestionSeeder::class,
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
            PraxisTrainerSeeder::class,
            ComboPraxisTrainerSeeder::class,
            TenantSeeder::class,
            UserAndEntitlementSeeder::class,
            DemoLearningActivitySeeder::class,
        ]);
    }
}
