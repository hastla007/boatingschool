<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\ExamBlueprint;
use App\Models\ExamRuleSet;
use App\Models\Module;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class SuperadminExamRuleSetTest extends TestCase
{
    use InteractsWithTenants;

    private array $courseIds = [];

    private array $moduleIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        $this->onAdmin(function () {
            CourseDefinition::withoutGlobalScopes()->whereIn('id', $this->courseIds)->delete();
            Module::whereIn('id', $this->moduleIds)->delete();
        });
        parent::tearDown();
    }

    private function setUpRuleSet(): array
    {
        return $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create(['tenant_id' => null, 'code' => 'TEST-RULES-'.uniqid(), 'name' => 'Regelkurs', 'course_type' => 'full', 'status' => 'published']);
            $this->courseIds[] = $course->id;
            $a = Module::create(['code' => 'TEST_RA_'.uniqid(), 'name' => 'Teil A', 'version' => '1', 'status' => 'published']);
            $b = Module::create(['code' => 'TEST_RB_'.uniqid(), 'name' => 'Teil B', 'version' => '1', 'status' => 'published']);
            $this->moduleIds = [$a->id, $b->id];
            $ruleSet = ExamRuleSet::create(['course_id' => $course->id, 'version' => 'v1', 'time_limit_seconds' => 1800, 'passing_rule' => ['min_correct_ratio' => 0.75], 'status' => 'draft']);
            ExamBlueprint::create(['rule_set_id' => $ruleSet->id, 'module_id' => $a->id, 'question_count' => 20]);

            return [$course, $ruleSet, $a, $b];
        });
    }

    public function test_superadmin_edits_limits_mix_parts_and_releases_the_rule_set(): void
    {
        $superadmin = $this->createSuperAdmin();
        [$course, $ruleSet, $a, $b] = $this->setUpRuleSet();

        $this->actingAs($superadmin)->get("/superadmin/courses/{$course->id}/exam-rules")->assertOk()->assertSee('Regelwerk v1')->assertSee('value="15"', false);

        $this->actingAs($superadmin)->patch("/superadmin/exam-rule-sets/{$ruleSet->id}", [
            'time_limit_minutes' => 45, 'min_correct' => 23, 'of' => 30, 'status' => 'published', 'verified' => '1', 'note' => 'Plus Kartenaufgabe',
            'blueprints' => [['module_id' => $a->id, 'question_count' => 7], ['module_id' => $b->id, 'question_count' => 23]],
            'parts' => [['label' => 'Basis', 'module' => $a->code, 'min_correct' => 5, 'of' => 7], ['label' => '', 'module' => '', 'min_correct' => '', 'of' => '']],
        ])->assertSessionHasNoErrors();

        $fresh = $this->onAdmin(fn () => ExamRuleSet::with('blueprints')->find($ruleSet->id));
        $this->assertSame(2700, $fresh->time_limit_seconds);
        $this->assertEqualsWithDelta(23 / 30, $fresh->passing_rule['min_correct_ratio'], 1e-9);
        $this->assertEquals([['label' => 'Basis', 'module' => $a->code, 'min_correct' => 5, 'of' => 7]], $fresh->passing_rule['parts']);
        $this->assertSame('Plus Kartenaufgabe', $fresh->note());
        $this->assertSame(30, (int) $fresh->blueprints->sum('question_count'));
        $this->assertTrue($fresh->isVerified());
        $this->assertSame($ruleSet->id, $this->onAdmin(fn () => $course->fresh()->activeExamRuleSet()?->id));

        // Freigabe zurücknehmen: Prüfung nicht mehr startbar.
        $this->actingAs($superadmin)->patch("/superadmin/exam-rule-sets/{$ruleSet->id}", [
            'time_limit_minutes' => 45, 'min_correct' => 23, 'of' => 30, 'status' => 'published',
            'blueprints' => [['module_id' => $a->id, 'question_count' => 30]],
        ])->assertSessionHasNoErrors();
        $this->assertNull($this->onAdmin(fn () => $course->fresh()->activeExamRuleSet()));
        $this->assertSame(1, $this->onAdmin(fn () => ExamBlueprint::where('rule_set_id', $ruleSet->id)->count()), 'entferntes Modul aus dem Mix gelöscht');
    }

    public function test_invalid_rules_are_rejected(): void
    {
        $superadmin = $this->createSuperAdmin();
        [, $ruleSet, $a] = $this->setUpRuleSet();
        $base = ['time_limit_minutes' => 30, 'status' => 'draft', 'blueprints' => [['module_id' => $a->id, 'question_count' => 10]]];

        $this->actingAs($superadmin)->patch("/superadmin/exam-rule-sets/{$ruleSet->id}", $base + ['min_correct' => 12, 'of' => 10])->assertSessionHasErrors('min_correct');
        $this->actingAs($superadmin)->patch("/superadmin/exam-rule-sets/{$ruleSet->id}", $base + ['min_correct' => 8, 'of' => 10, 'parts' => [['label' => 'Teil', 'module' => $a->code, 'min_correct' => 9, 'of' => 7]]])->assertSessionHasErrors('parts');
        $this->actingAs($superadmin)->patch("/superadmin/exam-rule-sets/{$ruleSet->id}", ['blueprints' => [['module_id' => $a->id, 'question_count' => 5], ['module_id' => $a->id, 'question_count' => 5]]] + $base + ['min_correct' => 8, 'of' => 10])->assertSessionHasErrors('blueprints');
        $this->assertSame(1800, $this->onAdmin(fn () => ExamRuleSet::find($ruleSet->id)->time_limit_seconds));
    }
}
