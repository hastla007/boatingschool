<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use Tests\TestCase;

/**
 * Bug-Review (AA17: Quizfragen in falscher Lektion): FknSknLessonQuestionSeeder
 * (begleitet von Migration
 * 2024_02_01_000485_move_misfiled_fkn_skn_zulassungszeichen_video_quiz_questions)
 * ordnete FKN-0025, FKN-0026 und SKN-0039 bisher der falschen Lektion zu --
 * alle drei drehen sich inhaltlich um die amtliche Kennzeichnung
 * pyrotechnischer Munition (Zulassungszeichen nach § 10 BeschG bzw. deren
 * Klasseneinteilung), nicht um das Erkennen/Unterscheiden der Signalmittel
 * selbst oder um Waffenrecht. FKN-0033 bzw. SKN-0049 zum exakt selben Thema
 * stehen bereits zutreffend unter "Kennzeichnung, Haltbarkeit, Aufbewahrung
 * und Entsorgung".
 */
class FknSknZulassungszeichenLessonPlacementTest extends TestCase
{
    private const TARGET_LESSON = 'Kennzeichnung, Haltbarkeit, Aufbewahrung und Entsorgung';

    public function test_fkn_zulassungszeichen_questions_are_attached_to_the_kennzeichnung_lesson(): void
    {
        $this->assertQuestionAttachedToLesson('FKN', 'fkn_official', '25');
        $this->assertQuestionAttachedToLesson('FKN', 'fkn_official', '26');
    }

    public function test_skn_zulassungszeichen_question_is_attached_to_the_kennzeichnung_lesson(): void
    {
        $this->assertQuestionAttachedToLesson('SKN', 'skn_official', '39');
    }

    public function test_add_fkn_to_skn_mirrors_the_same_correct_placement(): void
    {
        $this->assertQuestionAttachedToLesson('ADD-FKN-TO-SKN', 'skn_official', '39');
    }

    private function assertQuestionAttachedToLesson(string $courseCode, string $contentRole, string $officialNumber): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', $courseCode)->whereNull('tenant_id')->firstOrFail();

        $lessonTitle = null;

        foreach ($course->videoModules()->with('lessons.questions')->get() as $module) {
            foreach ($module->lessons as $lesson) {
                foreach ($lesson->questions as $question) {
                    if ($question->content_role === $contentRole && $question->official_number === $officialNumber) {
                        $lessonTitle = $lesson->title;
                    }
                }
            }
        }

        $this->assertSame(
            self::TARGET_LESSON,
            $lessonTitle,
            "{$contentRole} #{$officialNumber} muss der Lektion \"".self::TARGET_LESSON."\" zugeordnet sein (Kurs {$courseCode})."
        );
    }
}
