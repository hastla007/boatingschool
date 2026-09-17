<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\Favorite;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LearningService;
use Illuminate\Database\Seeder;

/**
 * Erzeugt plausible Lernaktivität für die Demo-Lernenden, damit Dashboard,
 * Fortschritt und Smarttrainer nicht leer aussehen. Ein Teil der Fragen
 * bekommt mehrere aufeinanderfolgende richtige Antworten, damit der
 * "gefestigt"-Zustand (current_streak >= 3) sichtbar wird -- ein einzelner
 * Attempt pro Frage würde sonst nie zu einem sichtbaren Fortschrittsring
 * führen.
 */
class DemoLearningActivitySeeder extends Seeder
{
    public function run(LearningService $learningService): void
    {
        $this->simulate($learningService, 'mueller', 'max@bootsschule-mueller.de', 'SBF-SEE', 90, masteredRatio: 0.55);
        $this->simulate($learningService, 'mueller', 'erika@bootsschule-mueller.de', 'SBF-BIN-MOTOR', 40, masteredRatio: 0.35);
        $this->simulate($learningService, 'hanse-kiel', 'lena@hanse-bootsschule-kiel.de', 'SRC', 60, masteredRatio: 0.65);
    }

    private function simulate(LearningService $service, string $tenantSlug, string $email, string $courseCode, int $questionCount, float $masteredRatio): void
    {
        $tenant = Tenant::where('slug', $tenantSlug)->firstOrFail();
        $user = User::where('email', $email)->firstOrFail();
        $course = CourseDefinition::withoutGlobalScopes()->where('code', $courseCode)->whereNull('tenant_id')->firstOrFail();

        $course->load('modules.questions.revisions.answers');
        $questions = $course->modules->flatMap(fn ($m) => $m->questions)->unique('id')->shuffle()->take($questionCount);

        foreach ($questions->take(4) as $question) {
            Favorite::firstOrCreate([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'question_id' => $question->id,
            ]);
        }

        foreach ($questions as $index => $question) {
            $revision = $question->revisions->firstWhere('editorial_status', 'published');
            if (! $revision) {
                continue;
            }

            $correctAnswer = $revision->answers->firstWhere('is_correct', true);
            $wrongAnswer = $revision->answers->firstWhere('is_correct', false);
            $roll = ($index % $questionCount) / $questionCount;

            // Verteilung: gefestigt (3x richtig) / in Bearbeitung (1x richtig) /
            // unsicher (1x falsch) / falsch dann richtig (in Bearbeitung, Streak 1).
            $sequence = match (true) {
                $roll < $masteredRatio => [true, true, true],
                $roll < $masteredRatio + 0.2 => [true],
                $roll < $masteredRatio + 0.35 => [false],
                default => [false, true],
            };

            foreach ($sequence as $wasCorrect) {
                $answer = $wasCorrect ? $correctAnswer : $wrongAnswer;
                $service->recordAttempt($tenant, $user, $revision, $answer, 'smarttrainer', $course, mt_rand(2000, 15000));
            }
        }
    }
}
