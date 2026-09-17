<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LearningService;
use Illuminate\Database\Seeder;

/**
 * Erzeugt plausible Lernaktivität für die Demo-Lernenden, damit Dashboard,
 * Fortschritt und Smarttrainer nicht leer aussehen.
 */
class DemoLearningActivitySeeder extends Seeder
{
    public function run(LearningService $learningService): void
    {
        $this->simulate($learningService, 'mueller', 'max@bootsschule-mueller.de', 'SBF-SEE', 45, 0.72);
        $this->simulate($learningService, 'mueller', 'erika@bootsschule-mueller.de', 'SBF-BIN-MOTOR', 20, 0.6);
        $this->simulate($learningService, 'hanse-kiel', 'lena@hanse-bootsschule-kiel.de', 'SRC', 30, 0.8);
    }

    private function simulate(LearningService $service, string $tenantSlug, string $email, string $courseCode, int $attempts, float $accuracy): void
    {
        $tenant = Tenant::where('slug', $tenantSlug)->firstOrFail();
        $user = User::where('email', $email)->firstOrFail();
        $course = CourseDefinition::withoutGlobalScopes()->where('code', $courseCode)->whereNull('tenant_id')->firstOrFail();

        $course->load('modules.questions.revisions.answers');
        $questions = $course->modules->flatMap(fn ($m) => $m->questions)->unique('id')->shuffle()->take($attempts);

        foreach ($questions as $question) {
            $revision = $question->revisions->firstWhere('editorial_status', 'published');
            if (! $revision) {
                continue;
            }

            $correctAnswer = $revision->answers->firstWhere('is_correct', true);
            $wrongAnswer = $revision->answers->firstWhere('is_correct', false);
            $answerToPick = mt_rand() / mt_getrandmax() < $accuracy ? $correctAnswer : $wrongAnswer;

            $service->recordAttempt($tenant, $user, $revision, $answerToPick, 'smarttrainer', $course, mt_rand(2000, 15000));
        }
    }
}
