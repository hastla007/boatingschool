<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoModule;
use Illuminate\Database\Seeder;

/**
 * Einzelne Kapitel von SBF See hatten bisher weniger Lektionen, als es für
 * das "Zu den Fragen"-Quiz (siehe VideoLessonQuestionSeeder) amtliche Fragen
 * in der zentralen Fragenbasis gibt. Ergänzt die fehlenden Lektionen mit
 * einem öffentlichen Platzhaltervideo, bis echtes, lizenziertes
 * Kursmaterial vorliegt -- wie bei den übrigen Kapiteln. NEW_CHAPTERS legt
 * dafür ggf. fehlende Kapitel direkt vor einem bestehenden Referenzkapitel an.
 */
class SeemannschaftVideoSeeder extends Seeder
{
    private const PLACEHOLDER_VIDEO = 'https://www.w3schools.com/html/mov_bbb.mp4';

    /** @var array<int, array{0: string, 1: string}> [Neues Kapitel, Titel des Kapitels davor]. */
    private const NEW_CHAPTERS = [
        ['Berufsschifffahrt', 'Ausweichregeln'],
    ];

    /** @var array<string, array<int, array{0: string, 1: int}>> Kapitel => [Lektion, Dauer in Sekunden]. */
    private const EXTRA_LESSONS = [
        'Seemannschaft' => [
            ['Ankern', 139],
            ['Feuer an Bord', 76],
            ['Nebel', 86],
        ],
        'Wetterkunde' => [
            ['Entstehung des Wetters', 152],
            ['Der Wind', 123],
            ['Wetterkarten', 187],
        ],
        'Ausweichregeln' => [
            ['Ausweichregeln für alle Fahrzeuge', 378],
            ['Ausweichregeln für Maschinenfahrzeuge', 87],
            ['Ausweichregeln für Segelfahrzeuge', 124],
        ],
        'Berufsschifffahrt' => [
            ['Seeverkehrsrecht', 289],
            ['Seefunk', 194],
            ['Verkehrstrennungsgebiete', 87],
            ['Schallsignale', 110],
        ],
        'Lichter und Signale' => [
            ['Spezialfälle', 454],
        ],
    ];

    public function run(): void
    {
        $sbfSee = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();

        if (! $sbfSee) {
            return;
        }

        $this->createMissingChapters($sbfSee);

        foreach (self::EXTRA_LESSONS as $chapterTitle => $lessons) {
            $chapter = $sbfSee->videoModules()->where('title', $chapterTitle)->first();

            if (! $chapter) {
                continue;
            }

            $nextSortOrder = (int) $chapter->lessons()->max('sort_order');

            foreach ($lessons as [$title, $duration]) {
                if ($chapter->lessons()->where('title', $title)->exists()) {
                    continue;
                }

                $nextSortOrder++;
                VideoLesson::create([
                    'video_module_id' => $chapter->id,
                    'title' => $title,
                    'video_url' => self::PLACEHOLDER_VIDEO,
                    'duration_seconds' => $duration,
                    'sort_order' => $nextSortOrder,
                ]);
            }
        }
    }

    private function createMissingChapters(CourseDefinition $sbfSee): void
    {
        foreach (self::NEW_CHAPTERS as [$chapterTitle, $beforeTitle]) {
            if ($sbfSee->videoModules()->where('title', $chapterTitle)->exists()) {
                continue;
            }

            $before = $sbfSee->videoModules()->where('title', $beforeTitle)->first();

            if (! $before) {
                continue;
            }

            $sbfSee->videoModules()
                ->where('sort_order', '>', $before->sort_order)
                ->increment('sort_order');

            VideoModule::create([
                'course_id' => $sbfSee->id,
                'title' => $chapterTitle,
                'sort_order' => $before->sort_order + 1,
            ]);
        }
    }
}
