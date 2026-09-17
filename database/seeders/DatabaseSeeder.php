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

        $this->call([
            CourseSeeder::class,
            VideoCourseSeeder::class,
            NavigationTaskSeeder::class,
            ExamRuleSetSeeder::class,
            ExamPaperSeeder::class,
            PraxisTrainerSeeder::class,
            TenantSeeder::class,
            UserAndEntitlementSeeder::class,
            DemoLearningActivitySeeder::class,
        ]);
    }
}
