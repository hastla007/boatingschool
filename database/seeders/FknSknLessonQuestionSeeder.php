<?php

namespace Database\Seeders;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use Illuminate\Database\Seeder;

/**
 * Demo-Bestückung des "Zu den Fragen"-Quiz für die gemeinsame FKN/SKN-
 * Videobibliothek (siehe FknSknVideoCourseSeeder): für FKN-Vollkurs-
 * Lektionen werden Fragen aus dem FKN-Katalog verknüpft, für SKN-
 * Vollkurs- und Ergänzungskurs-Lektionen aus dem SKN-Katalog -- auch
 * wenn dieselbe Videolektion (gleicher Titel/gleiche Video-URL) in
 * mehreren Kursen auftaucht, bekommt sie die zum jeweiligen Kurskatalog
 * passenden Fragen, da FKN und SKN getrennte Fragenkataloge sind.
 * Video 1 ("Überblick") bleibt wie die anderen Einstiegs-/Orientierungs-
 * lektionen in diesem Projekt bewusst ohne Quiz.
 */
class FknSknLessonQuestionSeeder extends Seeder
{
    /** @var array<string, int[]> Lektionstitel => FKN-Fragenkatalog-Nummern. */
    private const FKN_QUESTIONS = [
        'Rechtliche Grundlagen und Begriffe' => [1, 2, 45, 46, 47],
        'Signalmittel erkennen und unterscheiden' => [3, 8, 10, 22, 25, 26, 27, 28],
        'Sicherer Einsatz von Raketen, Handfackeln und Rauchsignalen' => [6, 7, 9, 30, 37, 38],
        'Kennzeichnung, Haltbarkeit, Aufbewahrung und Entsorgung' => [12, 13, 14, 15, 16, 33],
        'Versager und praktische Prüfung mit FKN-Signalmitteln' => [39, 40, 52, 58, 60],
    ];

    /** @var array<string, int[]> Lektionstitel => SKN-Fragenkatalog-Nummern. */
    private const SKN_QUESTIONS = [
        'Rechtliche Grundlagen und Begriffe' => [1, 2, 3, 65, 66, 67],
        'Signalmittel erkennen und unterscheiden' => [4, 9, 12, 35, 42],
        'Sicherer Einsatz von Raketen, Handfackeln und Rauchsignalen' => [7, 8, 11, 44, 53, 57],
        'Kennzeichnung, Haltbarkeit, Aufbewahrung und Entsorgung' => [14, 15, 16, 17, 19, 24, 25, 49],
        'Versager und praktische Prüfung mit FKN-Signalmitteln' => [59, 60, 73, 74, 84, 87],
        'SKN-Zusatz: Waffenrecht, Signalpistole und Munition' => [20, 22, 23, 27, 29, 30, 32, 33, 34, 38, 39],
        'SKN-Zusatz: praktische Prüfungsvorbereitung Signalpistole' => [48, 50, 51, 55, 56, 57, 58, 73, 77, 81, 82, 83, 85, 86],
    ];

    public function run(): void
    {
        $this->attachForCourse('FKN', 'fkn_official', self::FKN_QUESTIONS);
        $this->attachForCourse('SKN', 'skn_official', self::SKN_QUESTIONS);
        $this->attachForCourse('ADD-FKN-TO-SKN', 'skn_official', self::SKN_QUESTIONS);
    }

    /** @param array<string, int[]> $lessonQuestions */
    private function attachForCourse(string $courseCode, string $contentRole, array $lessonQuestions): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', $courseCode)->whereNull('tenant_id')->first();

        if (! $course) {
            return;
        }

        foreach ($course->videoModules as $module) {
            foreach ($module->lessons as $lesson) {
                $officialNumbers = $lessonQuestions[$lesson->title] ?? null;

                if ($officialNumbers) {
                    $this->attachQuestions($lesson, $contentRole, $officialNumbers);
                }
            }
        }
    }

    /** @param int[] $officialNumbers */
    private function attachQuestions(VideoLesson $lesson, string $contentRole, array $officialNumbers): void
    {
        if ($lesson->questions()->exists()) {
            return;
        }

        foreach ($officialNumbers as $sortOrder => $officialNumber) {
            $question = ContentQuestion::where('official_number', (string) $officialNumber)
                ->where('content_role', $contentRole)
                ->first();

            if ($question) {
                $lesson->questions()->syncWithoutDetaching([$question->id => ['sort_order' => $sortOrder + 1]]);
            }
        }
    }
}
