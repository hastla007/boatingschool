<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoLessonStep;
use App\Models\VideoModule;
use Illuminate\Database\Seeder;

/**
 * Videokurs für den Kombikurs "SBF See & Binnen": die bestehenden SBF-See-
 * Kapitel und die SBF-Binnen-spezifischen Kapitel (ohne "Knoten", das
 * bereits über SBF See gespiegelt wird) werden unter der eigenen Kurs-ID
 * des Kombikurses gespiegelt (Video-/Fortschritts-/Quizdaten hängen an
 * course_id bzw. video_lesson_id, nicht an course_module), inklusive der
 * bereits zugeordneten Quizfragen (video_lesson_question). So reicht ein
 * einzelnes Entitlement auf den Kombikurs für beide Teilkurse.
 */
class ComboVideoCourseSeeder extends Seeder
{
    private const EXCLUDED_BINNEN_CHAPTERS = ['Knoten'];

    public function run(): void
    {
        $combo = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE-BIN')->whereNull('tenant_id')->first();
        $sbfSee = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();
        $sbfBinnen = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-BIN-MOTOR')->whereNull('tenant_id')->first();

        if (! $combo || ! $sbfSee || ! $sbfBinnen || $combo->videoModules()->exists()) {
            return;
        }

        $sortOrder = 0;

        $sourceModules = $sbfSee->videoModules()->with(['lessons.steps', 'lessons.questions'])->get()
            ->concat(
                $sbfBinnen->videoModules()
                    ->whereNotIn('title', self::EXCLUDED_BINNEN_CHAPTERS)
                    ->with(['lessons.steps', 'lessons.questions'])
                    ->get()
            );

        foreach ($sourceModules as $sourceModule) {
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
