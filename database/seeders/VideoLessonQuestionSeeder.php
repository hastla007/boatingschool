<?php

namespace Database\Seeders;

use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use Illuminate\Database\Seeder;

/**
 * Demo-Bestückung des "Zu den Fragen"-Quiz (siehe video_lesson_question,
 * VideoLessonController/VideoQuizController): verknüpft ausgewählte, bereits
 * veröffentlichte Fragen aus der zentralen Fragenbasis mit einzelnen
 * Videolektionen, passend zum jeweiligen Lektionsinhalt.
 */
class VideoLessonQuestionSeeder extends Seeder
{
    public function run(): void
    {
        $sbfSee = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();

        if (! $sbfSee) {
            return;
        }

        $lesson = $sbfSee->videoModules()->where('title', 'Einführung in den Sportbootführerschein')->first()
            ?->lessons()->where('title', 'Motor, Bootsführer und Fahrer')->first();

        if (! $lesson || $lesson->questions()->exists()) {
            return;
        }

        $questionTexts = [
            'Was ist zu tun, wenn vor Antritt der Fahrt nicht feststeht, wer Schiffsführer ist?',
            'In welchen Fällen darf weder ein Sportboot geführt noch dessen Kurs oder Geschwindigkeit selbstständig bestimmt werden?',
            'Unter welchen Voraussetzungen darf ein Sportboot mit Elektromotor ohne Fahrerlaubnis geführt werden?',
            'Welche Sportboote sind von der Fahrerlaubnispflicht auf den Seeschifffahrtsstraßen ausgenommen?',
        ];

        foreach ($questionTexts as $sortOrder => $text) {
            $revision = ContentQuestionRevision::where('editorial_status', 'published')
                ->where('question_text', $text)
                ->first();

            if ($revision) {
                $lesson->questions()->syncWithoutDetaching([$revision->question_id => ['sort_order' => $sortOrder + 1]]);
            }
        }
    }
}
