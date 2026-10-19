<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoLessonStep;
use App\Models\VideoModule;
use Illuminate\Database\Seeder;

/**
 * Videokurs für den Ergänzungskurs "See → Binnen" (ADD-SEE-TO-BIN): die
 * SBF-Binnen-spezifischen Kapitel aus SBF-BIN-MOTOR werden unter der
 * eigenen Kurs-ID des Ergänzungskurses gespiegelt (Video-/Fortschrittsdaten
 * hängen an course_id, nicht an course_module), inklusive der bereits
 * zugeordneten Quizfragen (video_lesson_question). Das Kapitel "Knoten"
 * entfällt, da es bereits Teil von SBF See ist, das Voraussetzung für
 * diesen Ergänzungskurs ist.
 */
class AddSeeToBinVideoCourseSeeder extends Seeder
{
    private const EXCLUDED_CHAPTERS = ['Knoten'];

    public function run(): void
    {
        $addon = CourseDefinition::withoutGlobalScopes()->where('code', 'ADD-SEE-TO-BIN')->whereNull('tenant_id')->first();
        $sbfBinnen = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-BIN-MOTOR')->whereNull('tenant_id')->first();

        if (! $addon || ! $sbfBinnen || $addon->videoModules()->exists()) {
            return;
        }

        $sortOrder = 0;

        $sourceModules = $sbfBinnen->videoModules()
            ->whereNotIn('title', self::EXCLUDED_CHAPTERS)
            ->with(['lessons.steps', 'lessons.questions'])
            ->get();

        foreach ($sourceModules as $sourceModule) {
            $sortOrder++;
            $module = VideoModule::create([
                'course_id' => $addon->id,
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
                        'image_path' => $sourceStep->image_path,
                        'sort_order' => $sourceStep->sort_order,
                    ]);
                }

                foreach ($sourceLesson->questions as $sourceQuestion) {
                    $lesson->questions()->attach($sourceQuestion->id, [
                        'sort_order' => $sourceQuestion->pivot->sort_order,
                    ]);
                }
            }
        }
    }
}
