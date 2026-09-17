<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use Tests\TestCase;

/**
 * Prüft die von NavigationTaskSeeder aus database/data/
 * sbf_see_navigationsaufgaben.csv geladenen amtlichen Navigationsaufgaben
 * (Quelle: ELWIS): korrekte Anzahl von Aufgaben/Teilaufgaben sowie dass ein
 * aufgabenweiter Hinweis (z. B. Verkehrsblatt-Korrektur) korrekt für alle
 * Teilaufgaben derselben Aufgabe gilt statt pro Teilaufgabe dupliziert oder
 * verloren zu gehen.
 */
class NavigationTaskSeederTest extends TestCase
{
    public function test_sbf_see_has_15_official_navigation_tasks_with_9_questions_each(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->firstOrFail();

        $tasks = $course->navigationTasks;

        $this->assertCount(15, $tasks);
        $tasks->each(fn ($task) => $this->assertSame(9, $task->questions()->count(), "Aufgabe {$task->task_number}"));
    }

    public function test_only_the_task_with_an_official_correction_carries_a_hint(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->firstOrFail();

        $tasksWithHint = $course->navigationTasks->filter(fn ($task) => filled($task->hint));

        $this->assertCount(1, $tasksWithHint);
        $this->assertSame(1, $tasksWithHint->first()->task_number);
    }
}
