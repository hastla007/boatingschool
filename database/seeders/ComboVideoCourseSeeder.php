<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoLessonStep;
use App\Models\VideoModule;
use Illuminate\Database\Seeder;

/**
 * Videokurs für den Kombikurs "SBF See & Binnen": die bestehenden SBF-See-
 * Kapitel werden unter der eigenen Kurs-ID des Kombikurses gespiegelt
 * (Video-/Fortschrittsdaten hängen an course_id, nicht an course_module),
 * ergänzt um einen neuen SBF-Binnen-Videokurs. So reicht ein einzelnes
 * Entitlement auf den Kombikurs für beide Teilkurse.
 */
class ComboVideoCourseSeeder extends Seeder
{
    private const PLACEHOLDER_VIDEO = 'https://www.w3schools.com/html/mov_bbb.mp4';

    public function run(): void
    {
        $combo = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE-BIN')->whereNull('tenant_id')->first();
        $sbfSee = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();

        if (! $combo || ! $sbfSee || $combo->videoModules()->exists()) {
            return;
        }

        $sortOrder = 0;

        foreach ($sbfSee->videoModules()->with('lessons.steps')->get() as $sourceModule) {
            $sortOrder++;
            $module = VideoModule::create([
                'course_id' => $combo->id,
                'title' => $sourceModule->title,
                'sort_order' => $sortOrder,
            ]);

            foreach ($sourceModule->lessons as $sourceLesson) {
                $lesson = VideoLesson::create([
                    'video_module_id' => $module->id,
                    'title' => $sourceLesson->title,
                    'video_url' => $sourceLesson->video_url,
                    'duration_seconds' => $sourceLesson->duration_seconds,
                    'sort_order' => $sourceLesson->sort_order,
                ]);

                foreach ($sourceLesson->steps as $sourceStep) {
                    VideoLessonStep::create([
                        'video_lesson_id' => $lesson->id,
                        'title' => $sourceStep->title,
                        'sort_order' => $sourceStep->sort_order,
                    ]);
                }
            }
        }

        $binnenChapters = [
            'Grundlagen' => [
                ['Einführung in die Schifffahrt', 404],
                ['Rechtliche Bestimmungen', 200],
                ['Orientierung auf Wasserstraßen', 210],
                ['Bedeutung von Tonnen', 265],
            ],
            'Lichter, Zeichen und Töne' => [
                ['Grundlagen der Lichterführung', 348],
                ['Besonderheiten und Sonderregelungen', 284],
                ['Signalkörper und Lichter bei Nacht', 215],
                ['Schallsignale', 183],
                ['Smartmodus', 60],
                ['Notfallsignale', 128],
            ],
            'Bootsführung und Bordtechnik' => [
                ['Liegen und Fahren', 203],
                ['Schleusen', 182],
                ['Ausweichen', 231],
                ['Ausrüstung an Bord', 177],
                ['Elektrische Systeme', 172],
                ['Umweltschutz', 118],
                ['Gefahren von Gas an Bord', 92],
                ['Wassersport', 161],
            ],
        ];

        foreach ($binnenChapters as $title => $lessons) {
            $sortOrder++;
            $module = VideoModule::create([
                'course_id' => $combo->id,
                'title' => $title,
                'sort_order' => $sortOrder,
            ]);

            $lessonIndex = 0;
            foreach ($lessons as [$lessonTitle, $duration]) {
                VideoLesson::create([
                    'video_module_id' => $module->id,
                    'title' => $lessonTitle,
                    'video_url' => self::PLACEHOLDER_VIDEO,
                    'duration_seconds' => $duration,
                    'sort_order' => ++$lessonIndex,
                ]);
            }
        }
    }
}
