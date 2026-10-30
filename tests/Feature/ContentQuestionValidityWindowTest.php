<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use App\Models\Progress;
use App\Services\CourseProgressService;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review BB16: "Gültig ab"/"Gültig bis" (valid_from/valid_to auf der
 * veröffentlichten Revision, im Fragen-Editor setzbar) wurden bisher an
 * keiner Stelle geprüft, die Fragen für Lernende tatsächlich auswählt
 * (Smarttrainer, Zufallsprüfung, Video-Quiz, Fortschritts-/Favoriten-
 * Zähler, storeAttempt()) -- die Felder waren dadurch rein kosmetisch.
 * ContentQuestion::isCurrentlyValid()/scopeCurrentlyValid() prüfen jetzt
 * zusätzlich zum bereits etablierten active-Flag (Bug-Review F7/B12) das
 * Gültigkeitsfenster der veröffentlichten Revision -- fehlt eine
 * veröffentlichte Revision ganz, bleibt wie zuvor nur das active-Flag
 * maßgeblich (siehe Doc-Kommentar an isCurrentlyValid()).
 */
class ContentQuestionValidityWindowTest extends TestCase
{
    use InteractsWithTenants;

    private function firstActivePublishedQuestion(): ContentQuestion
    {
        $question = $this->onAdmin(fn () => ContentQuestion::where('active', true)
            ->whereHas('revisions', fn ($q) => $q->where('editorial_status', 'published'))
            ->first());
        $this->assertNotNull($question);

        return $question;
    }

    public function test_a_question_whose_validity_window_has_not_started_yet_is_not_currently_valid(): void
    {
        $question = $this->firstActivePublishedQuestion();
        $revision = $question->publishedRevision();
        $original = ['valid_from' => $revision->valid_from, 'valid_to' => $revision->valid_to];

        try {
            $this->onAdmin(fn () => $revision->update(['valid_from' => now()->addDay()->toDateString(), 'valid_to' => null]));

            $this->assertFalse($question->isCurrentlyValid());
        } finally {
            $this->onAdmin(fn () => $revision->update($original));
        }
    }

    public function test_a_question_whose_validity_window_has_already_ended_is_not_currently_valid(): void
    {
        $question = $this->firstActivePublishedQuestion();
        $revision = $question->publishedRevision();
        $original = ['valid_from' => $revision->valid_from, 'valid_to' => $revision->valid_to];

        try {
            $this->onAdmin(fn () => $revision->update(['valid_from' => null, 'valid_to' => now()->subDay()->toDateString()]));

            $this->assertFalse($question->isCurrentlyValid());
        } finally {
            $this->onAdmin(fn () => $revision->update($original));
        }
    }

    public function test_a_question_currently_inside_its_validity_window_is_valid(): void
    {
        $question = $this->firstActivePublishedQuestion();
        $revision = $question->publishedRevision();
        $original = ['valid_from' => $revision->valid_from, 'valid_to' => $revision->valid_to];

        try {
            $this->onAdmin(fn () => $revision->update([
                'valid_from' => now()->subDay()->toDateString(),
                'valid_to' => now()->addDay()->toDateString(),
            ]));

            $this->assertTrue($question->isCurrentlyValid());
        } finally {
            $this->onAdmin(fn () => $revision->update($original));
        }
    }

    public function test_course_progress_percent_reaches_100_once_the_only_unmastered_question_falls_outside_its_validity_window(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        [$targetQuestion, $otherQuestionIds] = $this->onAdmin(function () use ($course) {
            $course->loadMissing('modules.questions');
            $questions = $course->modules->flatMap(fn ($m) => $m->questions)->where('active', true)->unique('id')->values();

            return [$questions->first(), $questions->skip(1)->pluck('id')->all()];
        });

        $this->onAdmin(function () use ($tenant, $learner, $otherQuestionIds) {
            foreach ($otherQuestionIds as $questionId) {
                Progress::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $learner->id,
                    'question_id' => $questionId,
                    'attempt_count' => 3,
                    'correct_count' => 3,
                    'incorrect_count' => 0,
                    'current_streak' => 3,
                    'mastery_score' => 1,
                    'learning_state' => 'gefestigt',
                ]);
            }
        });

        $revision = $targetQuestion->publishedRevision();
        $original = ['valid_from' => $revision->valid_from, 'valid_to' => $revision->valid_to];

        try {
            $percentWithUnmasteredQuestionStillValid = $this->asTenant($tenant, fn () => app(CourseProgressService::class)->overallPercent($course, $tenant, $learner));
            $this->assertLessThan(100, $percentWithUnmasteredQuestionStillValid);

            $this->onAdmin(fn () => $revision->update(['valid_from' => now()->addDay()->toDateString(), 'valid_to' => null]));

            $percentOnceQuestionFallsOutsideItsWindow = $this->asTenant($tenant, fn () => app(CourseProgressService::class)->overallPercent($course, $tenant, $learner));
            $this->assertSame(100, $percentOnceQuestionFallsOutsideItsWindow);
        } finally {
            $this->onAdmin(fn () => $revision->update($original));
        }
    }
}
