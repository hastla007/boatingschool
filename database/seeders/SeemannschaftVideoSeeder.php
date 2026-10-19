<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use Illuminate\Database\Seeder;

/**
 * Das Kapitel "Seemannschaft" hatte bisher nur die Lektion "Anlegemanöver".
 * Für das "Zu den Fragen"-Quiz (siehe VideoLessonQuestionSeeder) werden
 * zwei weitere Lektionen ergänzt, deren Themen bereits amtliche Fragen in
 * der zentralen Fragenbasis haben: "Ankern" und "Feuer an Bord". Wie bei den
 * übrigen Kapiteln ein öffentliches Platzhaltervideo, bis echtes,
 * lizenziertes Kursmaterial vorliegt.
 */
class SeemannschaftVideoSeeder extends Seeder
{
    private const PLACEHOLDER_VIDEO = 'https://www.w3schools.com/html/mov_bbb.mp4';

    private const LESSONS = [
        ['Ankern', 139],
        ['Feuer an Bord', 76],
    ];

    public function run(): void
    {
        $sbfSee = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();
        $chapter = $sbfSee?->videoModules()->where('title', 'Seemannschaft')->first();

        if (! $chapter) {
            return;
        }

        $nextSortOrder = (int) $chapter->lessons()->max('sort_order');

        foreach (self::LESSONS as [$title, $duration]) {
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
