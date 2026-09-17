<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoLessonStep;
use App\Models\VideoModule;
use Illuminate\Database\Seeder;

/**
 * Beispiel-Videokurs für SBF See. Die Video-URL ist bewusst ein öffentliches
 * Platzhaltervideo -- vor Produktivbetrieb durch echtes, lizenziertes
 * Kursmaterial der jeweiligen Bootsschule ersetzen.
 */
class VideoCourseSeeder extends Seeder
{
    private const PLACEHOLDER_VIDEO = 'https://www.w3schools.com/html/mov_bbb.mp4';

    public function run(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();

        if (! $course || $course->videoModules()->exists()) {
            return;
        }

        $chapters = [
            'Einführung in den Sportbootführerschein' => [
                ['Alles zum Führerschein', 267],
                ['Motor, Bootsführer und Fahrer', 164],
                ['Begriffe', 143],
                ['Motorbootfahren', 372],
                ['Radeffekt', 163],
                ['Lebensgefahr im Wasser', 168],
                ['Tanken', 148],
                ['Umweltschutz', 175],
                ['Jetskis', 74],
            ],
            'Seemannschaft' => [
                ['Knotenkunde Grundlagen', 210],
                ['Anlegemanöver', 245],
            ],
            'Wetterkunde' => [
                ['Wolken und Wettervorhersage', 198],
                ['Wind und Seegang', 187],
            ],
            'Ausweichregeln' => [
                ['Vorfahrtsregeln auf dem Wasser', 220],
            ],
            'Lichter und Signale' => [
                ['Positionslaternen', 190],
                ['Schallsignale', 132],
            ],
        ];

        // Schritt-für-Schritt-Galerie für die Knotenlektion (analog zur
        // "Der Achtknoten"-Ansicht im Referenzprodukt). Ohne echtes
        // Bildmaterial werden hier nur benannte Platzhalter-Schritte
        // angelegt -- vor Produktivbetrieb durch echte Aufnahmen ersetzen.
        $knotSteps = [
            'Ende zur Schlaufe legen',
            'Ende um das stehende Part führen',
            'Ende durch die Schlaufe fädeln',
            'Knoten festziehen',
            'Fertigen Knoten prüfen',
        ];

        $chapterIndex = 0;
        foreach ($chapters as $title => $lessons) {
            $module = VideoModule::create([
                'course_id' => $course->id,
                'title' => $title,
                'sort_order' => ++$chapterIndex,
            ]);

            $lessonIndex = 0;
            foreach ($lessons as [$lessonTitle, $duration]) {
                $lesson = VideoLesson::create([
                    'video_module_id' => $module->id,
                    'title' => $lessonTitle,
                    'video_url' => self::PLACEHOLDER_VIDEO,
                    'duration_seconds' => $duration,
                    'sort_order' => ++$lessonIndex,
                ]);

                if ($lessonTitle === 'Knotenkunde Grundlagen') {
                    foreach ($knotSteps as $stepIndex => $stepTitle) {
                        VideoLessonStep::create([
                            'video_lesson_id' => $lesson->id,
                            'title' => $stepTitle,
                            'sort_order' => $stepIndex + 1,
                        ]);
                    }
                }
            }
        }
    }
}
