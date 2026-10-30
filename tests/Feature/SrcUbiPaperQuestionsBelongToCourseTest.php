<?php

namespace Tests\Feature;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\ExamSessionQuestion;
use App\Models\Favorite;
use App\Models\Tenant;
use App\Models\User;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (SRC-UBI: Fragen der Prüfungsbögen gehören nicht zum Kurs):
 * der Kombikurs lernt bewusst nur SRC + UBI_ERG, spiegelt aber die
 * UBI-Vollkurs-Bögen 101-112 -- deren Fragen aus dem Modul UBI lehnte der
 * Favoriten-Stern mit 403 ab, und "Falsche Fragen erneut lernen"
 * übersprang sie. Außerdem: Favoritenzähler der Prüfungsübersicht und
 * "Meine Fehler" der Prüfungsfragen zählten deaktivierte Fragen mit.
 */
class SrcUbiPaperQuestionsBelongToCourseTest extends TestCase
{
    use InteractsWithTenants;

    public function test_favorite_star_accepts_a_ubi_paper_question_outside_the_combo_modules(): void
    {
        [$tenant, $learner, $combo, $question] = $this->comboLearnerWithPaperOnlyQuestion();

        $this->actingAsInTenant($learner, $tenant)
            ->postJson("/favorites/{$question->id}", ['course_id' => $combo->id, 'context' => Favorite::CONTEXT_EXAM])
            ->assertNoContent();

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}/favorites/exam")
            ->assertOk()
            ->assertViewHas('examFavorites', fn ($favorites) => $favorites->pluck('question_id')->contains($question->id));

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}/exam")
            ->assertOk()
            ->assertViewHas('favoritesTotal', 1);
    }

    public function test_a_ubi_paper_question_can_be_sorted_into_a_collection(): void
    {
        [$tenant, $learner, $combo, $question] = $this->comboLearnerWithPaperOnlyQuestion();

        $this->actingAsInTenant($learner, $tenant)
            ->put("/courses/{$combo->id}/favorites/{$question->id}/collections", ['context' => Favorite::CONTEXT_EXAM, 'collection_ids' => []])
            ->assertRedirect();
    }

    public function test_relearning_wrong_exam_questions_serves_and_accepts_a_ubi_paper_question(): void
    {
        [$tenant, $learner, $combo, $question] = $this->comboLearnerWithPaperOnlyQuestion();

        $response = $this->actingAsInTenant($learner, $tenant)
            ->get("/courses/{$combo->id}/learn?mode=smarttrainer&questions={$question->id}");
        $response->assertOk()->assertViewIs('learning.question')
            ->assertViewHas('revision', fn ($revision) => $revision->question_id === $question->id);

        $revision = $this->onAdmin(fn () => $question->publishedRevision()->load('answers'));
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$combo->id}/learn/attempts?questions={$question->id}", [
            'revision_id' => $revision->id,
            'answer_id' => $revision->answers->firstWhere('is_correct', true)?->id,
            'answer_ids' => $revision->question_type === 'multi_choice' ? $revision->answers->where('is_correct', true)->pluck('id')->all() : null,
            'mode' => 'smarttrainer',
        ])->assertRedirect();
    }

    public function test_normal_learning_in_the_combo_still_stays_within_its_modules(): void
    {
        [$tenant, $learner, $combo, $question] = $this->comboLearnerWithPaperOnlyQuestion();

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$combo->id}/learn")
            ->assertOk()
            ->assertViewHas('revision', fn ($revision) => $revision->question_id !== $question->id);
    }

    public function test_exam_favorites_counter_and_wrong_list_exclude_a_deactivated_question(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        [$active, $disabled] = $this->onAdmin(function () use ($course, $tenant, $learner) {
            $paper = ExamPaper::where('course_id', $course->id)->orderBy('paper_number')->firstOrFail();
            $questions = ContentQuestion::whereIn('id', $paper->paperQuestions()->pluck('question_id'))->where('active', true)->orderBy('content_id')->take(2)->get();

            $session = ExamSession::create([
                'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'course_id' => $course->id,
                'rule_set_id' => ExamRuleSet::where('course_id', $course->id)->value('id'),
                'paper_id' => $paper->id, 'status' => 'evaluated', 'started_at' => now()->subHour(), 'submitted_at' => now()->subMinutes(30),
                'score' => 0, 'passed' => false,
            ]);

            foreach ($questions as $i => $question) {
                Favorite::create(['tenant_id' => $tenant->id, 'user_id' => $learner->id, 'question_id' => $question->id, 'context' => Favorite::CONTEXT_EXAM]);
                ExamSessionQuestion::create([
                    'tenant_id' => $tenant->id, 'exam_session_id' => $session->id, 'question_id' => $question->id,
                    'revision_id' => $question->publishedRevision()->id, 'position' => $i + 1, 'correct' => false, 'answered_at' => now()->subMinutes(40),
                ]);
            }

            return $questions->all();
        });

        // Echte, geteilte SRC-Frage -- der Aktiv-Status wird zwingend zurückgesetzt.
        $this->onAdmin(fn () => $disabled->update(['active' => false]));

        try {
            $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/exam")
                ->assertOk()->assertViewHas('favoritesTotal', 1);

            $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/favorites/exam")
                ->assertOk()
                ->assertViewHas('examWrongQuestions', fn ($questions) => $questions->pluck('id')->all() === [$active->id]);
        } finally {
            $this->onAdmin(fn () => $disabled->update(['active' => true]));
        }
    }

    /** @return array{0: Tenant, 1: User, 2: CourseDefinition, 3: ContentQuestion} */
    private function comboLearnerWithPaperOnlyQuestion(): array
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $combo = $this->existingCourse('SRC-UBI');
        $this->grantEntitlement($tenant, $learner, $combo);

        $question = $this->onAdmin(function () use ($combo) {
            $moduleQuestionIds = $combo->modules->flatMap(fn ($m) => $m->questions)->pluck('id');
            $paperQuestionIds = ExamPaper::where('course_id', $combo->id)->whereBetween('paper_number', [101, 112])->get()
                ->flatMap(fn ($p) => $p->paperQuestions->pluck('question_id'));

            return ContentQuestion::whereIn('id', $paperQuestionIds->diff($moduleQuestionIds))->currentlyValid()->orderBy('content_id')->firstOrFail();
        });

        return [$tenant, $learner, $combo, $question];
    }
}
