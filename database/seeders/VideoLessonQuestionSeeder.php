<?php

namespace Database\Seeders;

use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use Illuminate\Database\Seeder;

/**
 * Demo-Bestückung des "Zu den Fragen"-Quiz (siehe video_lesson_question,
 * VideoLessonController/VideoQuizController): verknüpft ausgewählte, bereits
 * veröffentlichte Fragen aus der zentralen Fragenbasis mit einzelnen
 * Videolektionen, passend zum jeweiligen Lektionsinhalt.
 */
class VideoLessonQuestionSeeder extends Seeder
{
    /** @var array<string, array<int, string>> Kapitel => Lektion => Fragetexte, je Teilkurs. */
    private const LESSON_QUESTIONS = [
        'Einführung in den Sportbootführerschein' => [
            'Motor, Bootsführer und Fahrer' => [
                'Was ist zu tun, wenn vor Antritt der Fahrt nicht feststeht, wer Schiffsführer ist?',
                'In welchen Fällen darf weder ein Sportboot geführt noch dessen Kurs oder Geschwindigkeit selbstständig bestimmt werden?',
                'Unter welchen Voraussetzungen darf ein Sportboot mit Elektromotor ohne Fahrerlaubnis geführt werden?',
                'Welche Sportboote sind von der Fahrerlaubnispflicht auf den Seeschifffahrtsstraßen ausgenommen?',
            ],
            'Motorbootfahren' => [
                'Welche Gefahren können entstehen, wenn ein kleines von einem größeren Fahrzeug überholt wird?',
                'Welches ist der günstigste Anlaufwinkel beim Anlegen?',
                'Wodurch wird bei einem Fahrzeug mit Außenbordmotor und ohne Ruderanlage die Ruderwirkung erzielt?',
                'Während der Fahrt sollte die Maschinenanlage ständig überwacht werden. Worauf muss besonders geachtet werden?',
                'Die Temperatur der Antriebsmaschine überschreitet die zulässigen Grenzwerte. Was könnte die mögliche Ursache sein?',
                'Die Ladekontrolllampe erlischt nach dem Starten nicht. Was könnte die mögliche Ursache sein?',
                'Die Ölkontrollleuchte leuchtet nach dem Starten weiter. Was könnte die mögliche Ursache sein?',
                'Der Motor ist gestartet worden. Was kann die Ursache sein, wenn nach dem Einkuppeln der Antriebswelle der Motor stehenbleibt?',
                'Ein Außenborder mit gefülltem Tank bleibt während der Fahrt stehen. Was könnten die Ursachen sein?',
            ],
            'Radeffekt' => [
                'Was ist unter einem rechtsdrehenden Propeller zu verstehen?',
                'Was ist unter einem linksdrehenden Propeller zu verstehen?',
                'Was ist unter der indirekten Ruderwirkung (Radeffekt) des Propellers zu verstehen?',
                'Weshalb ist die Kenntnis der Propellerdrehrichtung von Bedeutung?',
                'Welche Anlegeseite ist mit rechtsdrehendem Propeller empfehlenswert und warum?',
            ],
        ],
    ];

    public function run(): void
    {
        $sbfSee = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();

        if (! $sbfSee) {
            return;
        }

        foreach (self::LESSON_QUESTIONS as $chapterTitle => $lessons) {
            $chapter = $sbfSee->videoModules()->where('title', $chapterTitle)->first();

            if (! $chapter) {
                continue;
            }

            foreach ($lessons as $lessonTitle => $questionTexts) {
                $lesson = $chapter->lessons()->where('title', $lessonTitle)->first();
                $this->attachQuestions($lesson, $questionTexts);
            }
        }
    }

    /** @param array<int, string> $questionTexts */
    private function attachQuestions(?VideoLesson $lesson, array $questionTexts): void
    {
        if (! $lesson || $lesson->questions()->exists()) {
            return;
        }

        foreach ($questionTexts as $sortOrder => $text) {
            $revision = ContentQuestionRevision::where('editorial_status', 'published')
                ->where('question_text', $text)
                ->first();

            if ($revision) {
                $lesson->questions()->syncWithoutDetaching([$revision->question_id => ['sort_order' => $sortOrder + 1]]);
            }
        }
    }
}
