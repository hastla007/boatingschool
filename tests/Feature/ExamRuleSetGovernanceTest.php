<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ExamBlueprint;
use App\Models\ExamRuleSet;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ExamRuleSetGovernanceTest extends TestCase
{
    use InteractsWithTenants;

    private array $restore = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->restore as $snap) {
                ExamRuleSet::whereKey($snap['id'])->update($snap['attrs']);
                ExamBlueprint::where('rule_set_id', $snap['id'])->delete();
                foreach ($snap['blueprints'] as $b) {
                    ExamBlueprint::create($b);
                }
            }
            AuditLog::whereIn('actor_user_id', $this->createdUserIds)->delete();
        });
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function snapshot(ExamRuleSet $rs): void
    {
        $this->restore[] = [
            'id' => $rs->id,
            'attrs' => ['time_limit_seconds' => $rs->time_limit_seconds, 'passing_rule' => json_encode($rs->passing_rule), 'status' => $rs->status, 'verified_at' => $rs->verified_at, 'verified_by' => $rs->verified_by],
            'blueprints' => $rs->blueprints()->get(['rule_set_id', 'module_id', 'question_count'])->map->only(['rule_set_id', 'module_id', 'question_count'])->all(),
        ];
    }

    private function form(ExamRuleSet $rs, array $override): array
    {
        $rule = $rs->passing_rule ?? [];

        return array_merge([
            'time_limit_minutes' => intdiv((int) $rs->time_limit_seconds, 60),
            'min_correct' => 23, 'of' => 30,
            'status' => 'published', 'verified' => '1',
            'blueprints' => $rs->blueprints->map(fn ($b) => ['module_id' => $b->module_id, 'question_count' => $b->question_count])->values()->all(),
            'parts' => collect($rule['parts'] ?? [])->map(fn ($p) => ['label' => $p['label'], 'module' => $p['module'], 'min_correct' => $p['min_correct'], 'of' => $p['of']])->all(),
        ], $override);
    }

    public function test_changing_rules_records_a_new_verification(): void
    {
        $superadmin = $this->createSuperAdmin();
        $see = $this->existingCourse('SBF-SEE');
        $seeRs = $this->onAdmin(fn () => ExamRuleSet::where('course_id', $see->id)->with('blueprints')->firstOrFail());
        $this->snapshot($seeRs);
        $verifiedAt = $seeRs->verified_at;

        $this->actingAs($superadmin)->patch("/superadmin/exam-rule-sets/{$seeRs->id}", $this->form($seeRs, ['min_correct' => 1, 'of' => 30, 'parts' => []]))->assertSessionHasNoErrors();
        $fresh = $this->onAdmin(fn () => $seeRs->fresh());
        $this->assertEqualsWithDelta(1 / 30, $fresh->passing_rule['min_correct_ratio'], 1e-9);
        $this->assertTrue($fresh->isVerified());
        $this->assertNotEquals($verifiedAt, $fresh->verified_at, 'geänderte Regeln gelten als neu geprüft');
        $this->assertSame($superadmin->id, $fresh->verified_by);
    }
}
