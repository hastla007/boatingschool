<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use Tests\TestCase;

/**
 * Bug-Review (Y6: zwei SKN-Fragen im Video-Quiz doppelt zugeordnet): siehe
 * FknSknLessonQuestionSeeder und die begleitende Migration
 * 2024_02_01_000481_remove_duplicate_skn_signalpistole_video_quiz_questions.
 * SKN-0057 und SKN-0073 waren in SKN_QUESTIONS zusätzlich zu ihrer
 * eigentlichen Lektion noch unter "SKN-Zusatz: praktische
 * Prüfungsvorbereitung Signalpistole" gelistet, wodurch dieselbe Frage im
 * Video-Quiz von SKN (und dem Ergänzungskurs ADD-FKN-TO-SKN) zweimal
 * auftauchte.
 */
class SknVideoQuizHasNoDuplicateQuestionsTest extends TestCase
{
    public function test_skn_video_quiz_has_no_question_attached_to_more_than_one_lesson(): void
    {
        $this->assertNoDuplicateQuestionsWithinCourse('SKN');
    }

    public function test_add_fkn_to_skn_video_quiz_has_no_question_attached_to_more_than_one_lesson(): void
    {
        $this->assertNoDuplicateQuestionsWithinCourse('ADD-FKN-TO-SKN');
    }

    private function assertNoDuplicateQuestionsWithinCourse(string $courseCode): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', $courseCode)->whereNull('tenant_id')->firstOrFail();

        $questionLessonCounts = [];

        foreach ($course->videoModules()->with('lessons.questions')->get() as $module) {
            foreach ($module->lessons as $lesson) {
                foreach ($lesson->questions as $question) {
                    $questionLessonCounts[$question->id] = ($questionLessonCounts[$question->id] ?? 0) + 1;
                }
            }
        }

        $duplicates = array_filter($questionLessonCounts, fn ($count) => $count > 1);

        $this->assertSame(
            [],
            array_keys($duplicates),
            "Im Video-Quiz von {$courseCode} ist mindestens eine Frage mehreren Lektionen zugeordnet."
        );
    }
}
