<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use Illuminate\Database\Seeder;

/**
 * Einzelne Kapitel von SBF See hatten bisher weniger Lektionen, als es für
 * das "Zu den Fragen"-Quiz (siehe VideoLessonQuestionSeeder) amtliche Fragen
 * in der zentralen Fragenbasis gibt. Ergänzt die fehlenden Lektionen mit
 * einem öffentlichen Platzhaltervideo, bis echtes, lizenziertes
 * Kursmaterial vorliegt -- wie bei den übrigen Kapiteln.
 */
class SeemannschaftVideoSeeder extends Seeder
{
    private const PLACEHOLDER_VIDEO = 'https://www.w3schools.com/html/mov_bbb.mp4';

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
        ],
    ];

    public function run(): void
    {
        $sbfSee = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();

        if (! $sbfSee) {
            return;
        }

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
}
