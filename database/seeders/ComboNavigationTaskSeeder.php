<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\NavigationQuestion;
use App\Models\NavigationTask;
use Illuminate\Database\Seeder;

/**
 * Navigationsaufgaben für den Kombikurs "SBF See & Binnen": wie beim
 * Videokurs (siehe ComboVideoCourseSeeder) hängen Navigationsaufgaben am
 * course_id des jeweiligen Kurses, nicht an den Kursmodulen. Damit die
 * "Navigationsaufgaben"-Kachel auch beim Kombikurs erscheint, werden
 * SBF-Sees amtliche Aufgaben unter der eigenen Kurs-ID des Kombikurses
 * gespiegelt.
 */
class ComboNavigationTaskSeeder extends Seeder
{
    public function run(): void
    {
        $combo = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE-BIN')->whereNull('tenant_id')->first();
        $sbfSee = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();

        if (! $combo || ! $sbfSee || $combo->navigationTasks()->exists()) {
            return;
        }

        foreach ($sbfSee->navigationTasks()->with('questions')->get() as $sourceTask) {
            $task = NavigationTask::create([
                'course_id' => $combo->id,
                'task_number' => $sourceTask->task_number,
                'scenario_text' => $sourceTask->scenario_text,
                'hint' => $sourceTask->hint,
                'sort_order' => $sourceTask->sort_order,
            ]);

            foreach ($sourceTask->questions as $sourceQuestion) {
                NavigationQuestion::create([
                    'navigation_task_id' => $task->id,
                    'question_number' => $sourceQuestion->question_number,
                    'question_text' => $sourceQuestion->question_text,
                    'answer_text' => $sourceQuestion->answer_text,
                    'sort_order' => $sourceQuestion->sort_order,
                ]);
            }
        }
    }
}
