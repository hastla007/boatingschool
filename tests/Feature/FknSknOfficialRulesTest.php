<?php

namespace Tests\Feature;

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\ExamBlueprint;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\PraxisTask;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * FKN/SKN nach den Unterlagen des Prüfungsausschusses Nord: SKN-Prüfung mit
 * 18 Fragen, 60 Minuten, 26 von 36 Punkten; Signalpistole wird bei abwärts
 * gerichteter Mündung geladen; Rauchtöpfe gibt es nur noch als P1.
 */
class FknSknOfficialRulesTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    private function runMigration(string $number): void
    {
        $migration = require glob(database_path("migrations/2024_02_01_{$number}_*.php"))[0];
        $migration->up();
    }

    public function test_the_skn_random_exam_has_18_questions_60_minutes_and_needs_26_of_36_points(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SKN');
        $this->grantEntitlement($tenant, $learner, $course);

        $ruleSet = $this->onAdmin(fn () => $course->activeExamRuleSet());
        $this->assertNotNull($ruleSet);
        $this->assertSame(3600, $ruleSet->time_limit_seconds);
        $this->assertEqualsWithDelta(26 / 36, $ruleSet->passing_rule['min_correct_ratio'], 1e-9);

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam")->assertRedirect();

        $questionCount = $this->asTenant($tenant, fn () => ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail()->questions()->count());
        $this->assertSame(18, $questionCount);
    }

    public function test_the_fkn_to_skn_addon_stays_unreleased(): void
    {
        // Wie weit der FKN angerechnet wird, legt der Prüfungsausschuss fest.
        $this->assertFalse($this->onAdmin(fn () => $this->existingCourse('ADD-FKN-TO-SKN')->hasStartableExam()));
    }

    public function test_migration_releases_the_skn_placeholder(): void
    {
        $this->onAdmin(function () {
            $ruleSet = ExamRuleSet::where('course_id', $this->existingCourse('SKN')->id)->where('version', 'draft-2026-09')->firstOrFail();

            DB::beginTransaction();
            try {
                $ruleSet->update(['time_limit_seconds' => 3600, 'passing_rule' => ['min_correct_ratio' => 0.75], 'status' => 'draft', 'verified_by' => null, 'verified_at' => null]);
                ExamBlueprint::where('rule_set_id', $ruleSet->id)->update(['question_count' => 10]);

                $this->runMigration('000564');

                $ruleSet->refresh();
                $this->assertTrue($ruleSet->isVerified());
                $this->assertEqualsWithDelta(26 / 36, $ruleSet->passing_rule['min_correct_ratio'], 1e-9);
                $this->assertSame([18], ExamBlueprint::where('rule_set_id', $ruleSet->id)->pluck('question_count')->all());
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_smoke_pots_are_no_longer_listed_as_p2(): void
    {
        $this->onAdmin(function () {
            foreach (['FKN-0061', 'SKN-0088'] as $contentId) {
                $revision = ContentQuestion::where('content_id', $contentId)->firstOrFail()->publishedRevision();
                $answer = $revision->answers()->where('is_correct', true)->value('answer_text');

                $this->assertStringNotContainsString('Rauchsignale orange', $answer, $contentId);
                $this->assertStringContainsString('nur noch als P1', $answer, $contentId);
                $this->assertStringContainsString('eine Überarbeitung des Katalogs ist angekündigt', $revision->feedback_correct, $contentId);
            }
        });
    }

    public function test_an_existing_install_gets_the_p2_correction_without_an_editor_mark(): void
    {
        $this->onAdmin(function () {
            $question = ContentQuestion::where('content_id', 'SKN-0088')->firstOrFail();
            $published = $question->publishedRevision();
            $answer = $published->answers()->where('answer_key', 'MODEL')->firstOrFail();
            $corrected = $answer->answer_text;
            ContentAnswer::whereKey($answer->id)->update(['answer_text' => 'Handsignale der Unterklasse P2 („Signalraketen rot", „Fallschirmsignalraketen rot" und „Rauchsignale orange").']);

            try {
                $this->runMigration('000562');

                $fixed = $question->fresh()->publishedRevision();
                $this->assertNotSame($published->id, $fixed->id);
                $this->assertSame($corrected, $fixed->answers()->where('answer_key', 'MODEL')->value('answer_text'));
                $this->assertFalse($fixed->edited_by_superadmin);
            } finally {
                $question->revisions()->where('id', '!=', $published->id)->where('revision_no', '>', $published->revision_no)->each(function ($revision) {
                    $revision->answers()->delete();
                    $revision->media()->detach();
                    $revision->delete();
                });
                ContentAnswer::whereKey($answer->id)->update(['answer_text' => $corrected]);
                ContentQuestionRevision::whereKey($published->id)->update(['editorial_status' => 'published']);
            }
        });
    }

    public function test_the_signal_pistol_is_loaded_with_the_muzzle_pointing_down_in_every_course(): void
    {
        $this->onAdmin(function () {
            $cards = PraxisTask::where('content_id', 'like', 'PRAXIS-SKN-0002%')->get();

            // SKN selbst und die Ergänzung FKN -> SKN.
            $this->assertCount(2, $cards);
            foreach ($cards as $card) {
                $this->assertStringContainsString('abwärts gerichteter Mündung', $card->antwort, $card->content_id);
                $this->assertStringContainsString('praktischen SKN-Prüfung', $card->quelle, $card->content_id);
            }
        });
    }

    public function test_migration_updates_existing_practice_cards_and_their_mirrors(): void
    {
        $this->onAdmin(function () {
            $ids = PraxisTask::where('content_id', 'like', 'PRAXIS-SKN-0002%')->pluck('antwort', 'id');

            try {
                PraxisTask::whereIn('id', $ids->keys())->update(['antwort' => 'Mündung nach oben halten (alt).']);

                $this->runMigration('000563');

                foreach (PraxisTask::whereIn('id', $ids->keys())->get() as $card) {
                    $this->assertStringContainsString('abwärts gerichteter Mündung', $card->antwort, $card->content_id);
                }
            } finally {
                foreach ($ids as $id => $antwort) {
                    PraxisTask::whereKey($id)->update(['antwort' => $antwort]);
                }
            }
        });
    }
}
