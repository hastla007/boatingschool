<?php

namespace Database\Seeders;

use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use Illuminate\Database\Seeder;

/**
 * Demo-Bestückung des "Zu den Fragen"-Quiz für SBF Binnen (siehe
 * video_lesson_question, VideoLessonController/VideoQuizController):
 * verknüpft ausgewählte, bereits veröffentlichte Fragen aus der zentralen
 * Fragenbasis mit einzelnen Videolektionen, passend zum jeweiligen
 * Lektionsinhalt. Spiegelt den Aufbau von VideoLessonQuestionSeeder
 * (dort für SBF See), nur für den Kurs SBF-BIN-MOTOR.
 */
class BinnenLessonQuestionSeeder extends Seeder
{
    /** @var array<string, array<int, string>> Kapitel => Lektion => Fragetexte. */
    private const LESSON_QUESTIONS = [
        'Grundlagen' => [
            'Einführung in die Schifffahrt' => [
                'In welchen Fällen darf weder ein Sportboot geführt noch dessen Kurs oder Geschwindigkeit selbstständig bestimmt werden?',
                'Unter welchen Voraussetzungen darf ein Sportboot mit Elektromotor ohne Fahrerlaubnis geführt werden?',
                'Für welche Sportboote ist der Sportbootführerschein mit dem Geltungsbereich Binnenschifffahrtsstraßen vorgeschrieben?',
                'Auf welchen Gewässern gilt der Sportbootführerschein mit dem Geltungsbereich Binnenschifffahrtsstraßen?',
                'Was beinhaltet die allgemeine Sorgfaltspflicht?',
                'Welche Anforderungen neben der körperlichen und geistigen Tauglichkeit und fachlichen Eignung muss der Führer eines Sportbootes auf Binnenschifffahrtsstraßen erfüllen, wenn die größte Nutzleistung der Antriebsmaschine 11,03 kW bei Verbrennungsmotoren bzw. 7,5 kW bei Elektromotoren Betriebsart S1 (Dauerbetrieb) oder weniger beträgt?',
                'Welche Anforderungen neben der körperlichen und geistigen Tauglichkeit und fachlichen Eignung muss der Führer eines Sportbootes auf dem Rhein erfüllen, wenn die Nutzleistung der Antriebsmaschine mehr als 11,03 kW bei Verbrennungsmotoren bzw. 7,5 kW bei Elektromotoren Betriebsart S1 (Dauerbetrieb) beträgt?',
                'Welche Anforderungen werden an die Person gestellt, mit der der Schiffsführer das Ruder eines Sportbootes mit Antriebsmaschine auf Binnenschifffahrtstraßen besetzen will?',
            ],
            'Rechtliche Bestimmungen' => [
                'Wo findet man die allgemeinen Verkehrsregeln für die Binnenschifffahrtsstraßen und den Rhein?',
                'Wo findet man die allgemeinen Verkehrsregeln für die Mosel und die Donau?',
                'Wo findet man Regeln für den Verkehr von Wassermotorrädern und für das Wasserskilaufen?',
            ],
            'Orientierung auf Wasserstraßen' => [
                'Was versteht man unter "Fahrwasser"?',
                'Was versteht man unter "Fahrrinne"?',
                'In welche Richtung werden bei Flüssen die Uferseiten als rechtes bzw. linkes Ufer bezeichnet?',
                'Was bedeutet "zu Berg" oder "Bergfahrt" auf Flüssen?',
            ],
        ],
    ];

    public function run(): void
    {
        $sbfBinnen = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-BIN-MOTOR')->whereNull('tenant_id')->first();

        if (! $sbfBinnen) {
            return;
        }

        foreach (self::LESSON_QUESTIONS as $chapterTitle => $lessons) {
            $chapter = $sbfBinnen->videoModules()->where('title', $chapterTitle)->first();

            if (! $chapter) {
                continue;
            }

            foreach ($lessons as $lessonTitle => $questionTexts) {
                $lesson = $chapter->lessons()->where('title', $lessonTitle)->first();
                $this->attachQuestions($lesson, $questionTexts);
            }
        }
    }

    /**
     * @param array<int, string|array{media: string}> $questions Fragetext, oder
     *        ['media' => '...'] um bei mehrdeutigem (mehrfach verwendetem)
     *        Fragetext über den Dateinamen des zugehörigen Bildes zu identifizieren.
     */
    private function attachQuestions(?VideoLesson $lesson, array $questions): void
    {
        if (! $lesson || $lesson->questions()->exists()) {
            return;
        }

        foreach ($questions as $sortOrder => $question) {
            $query = ContentQuestionRevision::where('editorial_status', 'published');

            $revision = is_array($question)
                ? $query->whereHas('media', fn ($q) => $q->where('storage_path', 'ilike', '%' . $question['media']))->first()
                : $query->where('question_text', $question)->first();

            if ($revision) {
                $lesson->questions()->syncWithoutDetaching([$revision->question_id => ['sort_order' => $sortOrder + 1]]);
            }
        }
    }
}
