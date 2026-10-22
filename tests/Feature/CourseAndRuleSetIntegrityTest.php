<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\ExamRuleSet;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B21: EntitlementService ignorierte course_definition.status
 * (draft/archived galten weiter als anbietbar/zugänglich),
 * CourseDefinition::activeExamRuleSet() ignorierte valid_from/valid_until
 * der Regelwerke, und für globale Kurse ließ sich derselbe Code mehrfach
 * vergeben (der DB-Unique-Index liegt auf (tenant_id, code); NULL-tenant_id
 * verhindert das nicht).
 */
class CourseAndRuleSetIntegrityTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    private array $createdRuleSetIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            foreach ($this->createdRuleSetIds as $id) {
                ExamRuleSet::where('id', $id)->delete();
            }
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    public function test_a_draft_course_cannot_be_offered_or_accessed(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->makeIsolatedCourse('draft');
        $entitlement = $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/learn");

        $response->assertForbidden();
    }

    public function test_an_archived_course_revokes_access_for_existing_entitlements(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->makeIsolatedCourse('published');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/learn")->assertOk();

        $this->onAdmin(fn () => $course->update(['status' => 'archived']));

        $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/learn")->assertForbidden();
    }

    public function test_an_expired_rule_set_is_not_treated_as_active(): void
    {
        [$course, $module] = $this->makeIsolatedCourseWithModule();

        $this->onAdmin(function () use ($course, $module) {
            $ruleSet = ExamRuleSet::create([
                'course_id' => $course->id,
                'version' => 'expired-'.uniqid(),
                'valid_from' => now()->subDays(60)->toDateString(),
                'valid_until' => now()->subDay()->toDateString(),
                'time_limit_seconds' => 3600,
                'passing_rule' => ['min_correct_ratio' => 0.75],
                'status' => 'published',
                'verified_at' => now(),
            ]);
            $this->createdRuleSetIds[] = $ruleSet->id;
        });

        $this->assertNull($course->activeExamRuleSet());
    }

    public function test_a_not_yet_effective_rule_set_is_not_treated_as_active(): void
    {
        [$course, $module] = $this->makeIsolatedCourseWithModule();

        $this->onAdmin(function () use ($course, $module) {
            $ruleSet = ExamRuleSet::create([
                'course_id' => $course->id,
                'version' => 'future-'.uniqid(),
                'valid_from' => now()->addDays(30)->toDateString(),
                'time_limit_seconds' => 3600,
                'passing_rule' => ['min_correct_ratio' => 0.75],
                'status' => 'published',
                'verified_at' => now(),
            ]);
            $this->createdRuleSetIds[] = $ruleSet->id;
        });

        $this->assertNull($course->activeExamRuleSet());
    }

    public function test_a_currently_valid_rule_set_among_an_expired_and_a_future_one_is_selected(): void
    {
        [$course, $module] = $this->makeIsolatedCourseWithModule();

        $current = $this->onAdmin(function () use ($course) {
            $expired = ExamRuleSet::create([
                'course_id' => $course->id,
                'version' => 'a-expired-'.uniqid(),
                'valid_from' => now()->subDays(60)->toDateString(),
                'valid_until' => now()->subDay()->toDateString(),
                'time_limit_seconds' => 3600,
                'passing_rule' => ['min_correct_ratio' => 0.75],
                'status' => 'published',
                'verified_at' => now(),
            ]);
            $this->createdRuleSetIds[] = $expired->id;

            $current = ExamRuleSet::create([
                'course_id' => $course->id,
                'version' => 'b-current-'.uniqid(),
                'valid_from' => now()->subDay()->toDateString(),
                'time_limit_seconds' => 3600,
                'passing_rule' => ['min_correct_ratio' => 0.75],
                'status' => 'published',
                'verified_at' => now(),
            ]);
            $this->createdRuleSetIds[] = $current->id;

            $future = ExamRuleSet::create([
                'course_id' => $course->id,
                'version' => 'c-future-'.uniqid(),
                'valid_from' => now()->addDays(30)->toDateString(),
                'time_limit_seconds' => 3600,
                'passing_rule' => ['min_correct_ratio' => 0.75],
                'status' => 'published',
                'verified_at' => now(),
            ]);
            $this->createdRuleSetIds[] = $future->id;

            return $current;
        });

        $this->assertSame($current->id, $course->activeExamRuleSet()->id);
    }

    public function test_a_duplicate_global_course_code_is_rejected(): void
    {
        $superadmin = $this->createSuperAdmin();
        $code = 'B21-DUP-'.uniqid();

        $first = $this->actingAs($superadmin)->post('/superadmin/courses', [
            'code' => $code,
            'name' => 'B21 Erster Kurs',
            'course_type' => 'full',
            'status' => 'published',
        ]);
        $first->assertRedirect();

        $this->onAdmin(function () use ($code) {
            $course = CourseDefinition::withoutGlobalScopes()->where('code', $code)->first();
            $this->createdCourseIds[] = $course->id;
        });

        $second = $this->actingAs($superadmin)->post('/superadmin/courses', [
            'code' => $code,
            'name' => 'B21 Zweiter Kurs',
            'course_type' => 'full',
            'status' => 'published',
        ]);

        $second->assertSessionHasErrors('code');
        $this->assertSame(1, CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('code', $code)->count());
    }

    private function makeIsolatedCourse(string $status): CourseDefinition
    {
        return $this->onAdmin(function () use ($status) {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-B21-'.uniqid(),
                'name' => 'Test Kurs B21',
                'course_type' => 'full',
                'status' => $status,
            ]);
            $this->createdCourseIds[] = $course->id;

            return $course;
        });
    }

    /** @return array{0: CourseDefinition, 1: \App\Models\Module} */
    private function makeIsolatedCourseWithModule(): array
    {
        $course = $this->makeIsolatedCourse('published');
        $module = $this->existingModule('SBF_BASIS');

        $this->onAdmin(fn () => $course->modules()->attach($module->id));

        return [$course, $module];
    }
}
