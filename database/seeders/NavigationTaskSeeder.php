<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\NavigationQuestion;
use App\Models\NavigationTask;
use Illuminate\Database\Seeder;

/**
 * Navigationsaufgaben-Trainer für SBF See: Übungsaufgaben mit einsehbarer
 * Musterlösung, getrennt von der strengen Prüfungssimulation. Szenarien und
 * Musterlösungen sind Platzhalter zu Demozwecken -- vor Produktivbetrieb
 * durch fachlich geprüfte, amtliche Navigationsaufgaben ersetzen.
 */
class NavigationTaskSeeder extends Seeder
{
    public function run(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();

        if (! $course || $course->navigationTasks()->exists()) {
            return;
        }

        $tasks = [
            [
                'scenario' => 'Ein Sportboot befindet sich am 05.05. in der Deutschen Bucht auf der Reise von Borkum nach Cuxhaven. '
                    .'Die Fahrt über Grund beträgt 8 kn. Um 10:00 Uhr wird die Leuchttonne "TG19/Weser 2" nahebei passiert. '
                    .'Von dieser Tonne wird der Kurs auf die Ansteuerungstonne der alten Weser "ST" abgesetzt.',
                'questions' => [
                    ['Wie lautet der rechtweisende Kurs (rwK) von "TG19/Weser 2" nach "ST"?', 'Aus der Seekarte gemessener rwK, z. B. 143°.'],
                    ['Die Ablenkung beträgt +4°, die Missweisung ist der Seekarte zu entnehmen. Wie lautet der missweisende Kurs (MgK)?', 'MgK = rwK − Missweisung − Ablenkung (Vorzeichen beachten).'],
                    ['Wie groß ist die Distanz zwischen "TG19/Weser 2" und "ST"?', 'Aus der Seekarte mit dem Kartenzirkel gemessene Distanz in sm.'],
                    ['In welcher Zeit wird die Distanz zwischen "TG19/Weser 2" und "ST" bei 8 kn zurückgelegt?', 'Zeit = Distanz ÷ Geschwindigkeit (in Stunden, dann in hh:mm umrechnen).'],
                ],
            ],
            [
                'scenario' => 'Ein Sportboot verlässt um 14:00 Uhr den Hafen von Helgoland mit Kurs auf die Tonne "Elbe 1". '
                    .'Die Geschwindigkeit über Grund beträgt 6 kn.',
                'questions' => [
                    ['Wie lautet der rechtweisende Kurs (rwK) von Helgoland nach "Elbe 1"?', 'Aus der Seekarte gemessener rwK.'],
                    ['Wie groß ist die Distanz zwischen Helgoland und "Elbe 1"?', 'Aus der Seekarte gemessene Distanz in sm.'],
                    ['Wann wird "Elbe 1" voraussichtlich erreicht?', 'Ankunftszeit = Abfahrtszeit + (Distanz ÷ Geschwindigkeit).'],
                ],
            ],
        ];

        foreach ($tasks as $taskIndex => $task) {
            $navigationTask = NavigationTask::create([
                'course_id' => $course->id,
                'task_number' => $taskIndex + 1,
                'scenario_text' => $task['scenario'],
                'sort_order' => $taskIndex + 1,
            ]);

            foreach ($task['questions'] as $questionIndex => [$questionText, $answerText]) {
                NavigationQuestion::create([
                    'navigation_task_id' => $navigationTask->id,
                    'question_number' => $questionIndex + 1,
                    'question_text' => $questionText,
                    'answer_text' => $answerText,
                    'sort_order' => $questionIndex + 1,
                ]);
            }
        }
    }
}
