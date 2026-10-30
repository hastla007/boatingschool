<?php

namespace Tests\Feature;

use App\Models\ExamBlueprint;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\Module;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Amtliche Bestehensgrenzen je Prüfungsteil: SBF See (Basis 5/7 UND See
 * 18/23, 60 Minuten), SBF Binnen (Basis 5/7 UND Binnen 18/23, 45
 * Minuten), SBF Binnen unter Segel (Basis 5/7, Binnen 18/23 und Segeln
 * 5/7, 60 Minuten), Ergänzung See -> Binnen (18/23, 35 Minuten), Ergänzung
 * Binnen -> See (18/23, 50 Minuten), UBI-Ergänzung (8/10, 30 Minuten)
 * und der Kombikurs SBF See & Binnen, dessen Binnen-Bögen die Basisfragen
 * nicht wiederholen.
 */
class SbfExamRuleSetsTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_sbf_see_needs_both_parts_not_just_enough_correct_answers_overall(): void
    {
        // 4/7 Basis + 23/23 See = 27/30, aber Basis nicht bestanden.
        $this->assertFalse($this->takePaper('SBF-SEE', 1, ['SBF_BASIS' => 4, 'SBF_SEE' => 23])->passed);
        // 7/7 Basis + 17/23 See = 24/30, aber See nicht bestanden.
        $this->assertFalse($this->takePaper('SBF-SEE', 1, ['SBF_BASIS' => 7, 'SBF_SEE' => 17])->passed);
        // Genau an beiden Grenzen.
        $this->assertTrue($this->takePaper('SBF-SEE', 1, ['SBF_BASIS' => 5, 'SBF_SEE' => 18])->passed);
    }

    public function test_sbf_binnen_needs_basis_5_of_7_and_binnen_18_of_23_in_45_minutes(): void
    {
        $session = $this->takePaper('SBF-BIN-MOTOR', 1, ['SBF_BASIS' => 5, 'SBF_BINNEN' => 18]);
        $this->assertTrue($session->passed);
        $this->assertSame(2700, $session->ruleSet->time_limit_seconds);

        $this->assertFalse($this->takePaper('SBF-BIN-MOTOR', 1, ['SBF_BASIS' => 4, 'SBF_BINNEN' => 23])->passed);
    }

    public function test_sbf_binnen_unter_segel_needs_all_three_parts_in_60_minutes(): void
    {
        $session = $this->takePaper('SBF-BIN-SEGEL', 1, ['SBF_BASIS' => 5, 'SBF_BINNEN' => 18, 'SBF_BINNEN_SEGELN' => 5]);
        $this->assertTrue($session->passed);
        $this->assertSame(3600, $session->ruleSet->time_limit_seconds);
        // 33 von 37 richtig reichen nicht, wenn die Segelfragen unter 5/7 liegen.
        $this->assertFalse($this->takePaper('SBF-BIN-SEGEL', 2, ['SBF_BASIS' => 7, 'SBF_BINNEN' => 22, 'SBF_BINNEN_SEGELN' => 4])->passed);
        $this->assertFalse($this->takePaper('SBF-BIN-SEGEL', 3, ['SBF_BASIS' => 4, 'SBF_BINNEN' => 23, 'SBF_BINNEN_SEGELN' => 7])->passed);
    }

    public function test_migration_releases_the_sbf_binnen_unter_segel_placeholder(): void
    {
        $migration = require database_path('migrations/2024_02_01_000561_verify_sbf_binnen_segel_exam_rule_set.php');

        $this->onAdmin(function () use ($migration) {
            $ruleSet = ExamRuleSet::where('course_id', $this->existingCourse('SBF-BIN-SEGEL')->id)->firstOrFail();

            DB::beginTransaction();
            try {
                $ruleSet->update(['time_limit_seconds' => 3600, 'passing_rule' => ['min_correct_ratio' => 0.75], 'status' => 'draft', 'verified_by' => null, 'verified_at' => null]);
                ExamBlueprint::where('rule_set_id', $ruleSet->id)->update(['question_count' => 10]);

                $migration->up();

                $ruleSet->refresh();
                $this->assertTrue($ruleSet->isVerified());
                $this->assertSame(['SBF_BASIS', 'SBF_BINNEN', 'SBF_BINNEN_SEGELN'], array_column($ruleSet->passing_rule['parts'], 'module'));
                $this->assertEqualsCanonicalizing([7, 23, 7], ExamBlueprint::where('rule_set_id', $ruleSet->id)->pluck('question_count')->all());
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_addon_papers_use_their_official_single_thresholds(): void
    {
        $this->assertTrue($this->takePaper('ADD-SEE-TO-BIN', 1, ['SBF_BINNEN' => 18])->passed);
        $this->assertFalse($this->takePaper('ADD-SEE-TO-BIN', 1, ['SBF_BINNEN' => 17])->passed);
        $this->assertTrue($this->takePaper('ADD-BIN-TO-SEE', 1, ['SBF_SEE' => 18])->passed);
        $this->assertTrue($this->takePaper('ADD-UBI-TO-SRC', 1, ['UBI_ERG' => 8])->passed);
        $this->assertFalse($this->takePaper('ADD-UBI-TO-SRC', 1, ['UBI_ERG' => 7])->passed);

        $this->onAdmin(function () {
            $limits = collect(['ADD-SEE-TO-BIN' => 2100, 'ADD-BIN-TO-SEE' => 3000, 'ADD-UBI-TO-SRC' => 1800, 'SBF-SEE' => 3600])
                ->map(fn ($seconds, $code) => $this->existingCourse($code)->activeExamRuleSet()?->time_limit_seconds);
            $this->assertSame(['ADD-SEE-TO-BIN' => 2100, 'ADD-BIN-TO-SEE' => 3000, 'ADD-UBI-TO-SRC' => 1800, 'SBF-SEE' => 3600], $limits->all());
        });
    }

    public function test_result_page_shows_each_part_and_the_navigation_note(): void
    {
        [$session, $tenant, $learner] = $this->takePaper('SBF-SEE', 1, ['SBF_BASIS' => 4, 'SBF_SEE' => 23], withActors: true);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/exam-sessions/{$session->id}/result");

        $response->assertOk();
        $response->assertSee('Nicht bestanden');
        $response->assertSee('Basisfragen: 4/7 (mind. 5/7)');
        $response->assertSee('See: 23/23 (mind. 18/23)');
        $response->assertSee('Navigationsaufgabe (mindestens 7 von 9 Punkten)');
    }

    public function test_papers_overview_shows_the_part_thresholds(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/exam");

        $response->assertOk();
        $response->assertDontSee('noch nicht freigegeben');
        $response->assertSee('Basisfragen 5/7 · See 18/23 · 60 Minuten');
    }

    public function test_sbf_combo_binnen_papers_skip_the_basis_questions_and_use_the_addon_threshold(): void
    {
        $this->onAdmin(function () {
            $combo = $this->existingCourse('SBF-SEE-BIN');
            $binnenModule = Module::where('code', 'SBF_BINNEN')->firstOrFail();

            foreach (range(101, 115) as $paperNumber) {
                $paper = ExamPaper::where('course_id', $combo->id)->where('paper_number', $paperNumber)->firstOrFail();
                $questionIds = $paper->paperQuestions()->pluck('question_id');
                $this->assertCount(23, $questionIds, "Kombi-Bogen {$paperNumber}");
                $this->assertSame(23, $binnenModule->questions()->whereIn('content_question.id', $questionIds)->count());
            }
        });

        $binnen = $this->takePaper('SBF-SEE-BIN', 101, ['SBF_BINNEN' => 18]);
        $this->assertTrue($binnen->passed);
        $this->assertSame($this->onAdmin(fn () => $this->existingCourse('ADD-SEE-TO-BIN')->activeExamRuleSet()->id), $binnen->rule_set_id);

        $see = $this->takePaper('SBF-SEE-BIN', 1, ['SBF_BASIS' => 4, 'SBF_SEE' => 23]);
        $this->assertFalse($see->passed);
        $this->assertSame($this->onAdmin(fn () => $this->existingCourse('SBF-SEE')->activeExamRuleSet()->id), $see->rule_set_id);
    }

    public function test_migration_switches_placeholders_and_rebuilds_the_combo_binnen_papers(): void
    {
        $migration = require database_path('migrations/2024_02_01_000477_verify_sbf_and_addon_exam_rule_sets.php');

        $this->onAdmin(function () use ($migration) {
            $see = $this->existingCourse('SBF-SEE');
            $ruleSet = ExamRuleSet::where('course_id', $see->id)->firstOrFail();
            $comboPaper = ExamPaper::where('course_id', $this->existingCourse('SBF-SEE-BIN')->id)->where('paper_number', 101)->firstOrFail();
            $fullBinnenPaper = ExamPaper::where('course_id', $this->existingCourse('SBF-BIN-MOTOR')->id)->where('paper_number', 1)->firstOrFail();

            DB::beginTransaction();
            try {
                $ruleSet->update(['time_limit_seconds' => 3600, 'passing_rule' => ['min_correct_ratio' => 0.75], 'status' => 'draft', 'verified_by' => null, 'verified_at' => null]);
                ExamBlueprint::where('rule_set_id', $ruleSet->id)->update(['question_count' => 10]);
                // Alter Stand: Kombi-Bogen 101 = voller SBF-Binnen-Bogen (30 Fragen).
                ExamPaperQuestion::where('exam_paper_id', $comboPaper->id)->delete();
                foreach ($fullBinnenPaper->paperQuestions as $question) {
                    ExamPaperQuestion::create(['exam_paper_id' => $comboPaper->id, 'question_id' => $question->question_id, 'position' => $question->position]);
                }

                $migration->up();

                $ruleSet->refresh();
                $this->assertTrue($ruleSet->isVerified());
                $this->assertSame(['SBF_BASIS', 'SBF_SEE'], array_column($ruleSet->passing_rule['parts'], 'module'));
                $this->assertEqualsCanonicalizing([7, 23], ExamBlueprint::where('rule_set_id', $ruleSet->id)->pluck('question_count')->all());
                $this->assertSame(23, ExamPaperQuestion::where('exam_paper_id', $comboPaper->id)->count());
            } finally {
                DB::rollBack();
            }
        });
    }

    /**
     * Beantwortet je Modul genau $correctPerModule[Modul] Fragen richtig, den Rest falsch.
     *
     * @param  array<string, int>  $correctPerModule
     */
    private function takePaper(string $courseCode, int $paperNumber, array $correctPerModule, bool $withActors = false): ExamSession|array
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse($courseCode);
        $this->grantEntitlement($tenant, $learner, $course);
        [$paper, $moduleOf] = $this->onAdmin(function () use ($course, $paperNumber, $correctPerModule) {
            $paper = ExamPaper::where('course_id', $course->id)->where('paper_number', $paperNumber)->firstOrFail();
            $moduleOf = [];
            foreach (array_keys($correctPerModule) as $code) {
                foreach (Module::where('code', $code)->firstOrFail()->questions()->pluck('content_question.id') as $id) {
                    $moduleOf[$id] ??= $code;
                }
            }

            return [$paper, $moduleOf];
        });

        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}")->assertRedirect();

        [$session, $sessionQuestions] = $this->asTenant($tenant, function () use ($tenant, $learner) {
            $session = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail();

            return [$session, $session->questions()->orderBy('position')->with('revision.answers')->get()];
        });

        $remaining = $correctPerModule;
        foreach ($sessionQuestions as $sq) {
            $module = $moduleOf[$sq->question_id] ?? null;
            $this->assertNotNull($module, "Frage {$sq->question_id} gehört zu keinem der erwarteten Module.");
            $answerCorrectly = $remaining[$module] > 0;
            $remaining[$module]--;

            $answer = $sq->revision->answers->firstWhere('is_correct', $answerCorrectly);
            $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/answers", [
                'position' => $sq->position,
                'answer_id' => $answer->id,
            ]);
        }

        $this->actingAsInTenant($learner, $tenant)->post("/exam-sessions/{$session->id}/finish")->assertRedirect();
        $session = $this->asTenant($tenant, fn () => $session->fresh());

        return $withActors ? [$session, $tenant, $learner] : $session;
    }
}
