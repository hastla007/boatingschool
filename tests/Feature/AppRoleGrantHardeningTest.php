<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\ExamSessionQuestion;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview M7 (Defense in Depth): die App-Rolle (RLS-Verbindung
 * "pgsql") darf plattformweiten Content nur noch lesen, nie schreiben --
 * zuvor hätte ein kompromittierter oder fehlerhafter Code-Pfad im
 * Mandantenkontext z. B. beliebige neue, global gültige Coupon-Codes
 * anlegen oder den zentralen Fragenkatalog verändern können. Außerdem hat
 * exam_session_question jetzt eine eigene tenant_id mit RLS-Policy, statt
 * sich allein auf sorgfältige Controller-Queries zu verlassen.
 */
class AppRoleGrantHardeningTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdRuleSetIds = [];

    protected function tearDown(): void
    {
        // Reihenfolge wichtig: exam_session referenziert rule_set_id (wird
        // erst durch das Löschen des Tenants kaskadiert weggeräumt), und
        // exam_rule_set.verified_by referenziert den Nutzer.
        $this->cleanUpCreatedTenants();

        $this->onAdmin(function () {
            foreach ($this->createdRuleSetIds as $id) {
                ExamRuleSet::where('id', $id)->delete();
            }
        });

        $this->cleanUpCreatedUsers();

        parent::tearDown();
    }

    public function test_app_role_cannot_write_to_the_global_question_catalog(): void
    {
        $tenant = $this->createTestTenant();
        app(TenantContext::class)->set($tenant);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/permission denied for table content_question/');

        DB::table('content_question')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'content_id' => 'HACK-'.uniqid(),
        ]);
    }

    public function test_app_role_cannot_mint_a_free_coupon(): void
    {
        $tenant = $this->createTestTenant();
        $course = $this->existingCourse('SRC');
        app(TenantContext::class)->set($tenant);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/permission denied for table coupon/');

        DB::table('coupon')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'code' => 'MINTED-'.uniqid(),
            'course_id' => $course->id,
            'tenant_id' => null,
            'is_renewal' => false,
            'created_at' => now(),
        ]);
    }

    public function test_app_role_cannot_delete_audit_log_entries(): void
    {
        $tenant = $this->createTestTenant();
        app(TenantContext::class)->set($tenant);

        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('audit_log')->insert([
            'id' => $id,
            'tenant_id' => $tenant->id,
            'action' => 'test.action',
            'entity_type' => 'tenant',
            'entity_id' => $tenant->id,
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/permission denied for table audit_log/');

        DB::table('audit_log')->where('id', $id)->delete();
    }

    public function test_app_role_cannot_insert_a_platform_wide_audit_log_entry(): void
    {
        $tenant = $this->createTestTenant();
        app(TenantContext::class)->set($tenant);

        $this->expectException(QueryException::class);

        DB::table('audit_log')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => null,
            'action' => 'test.platform_action',
            'entity_type' => 'tenant',
            'entity_id' => $tenant->id,
            'created_at' => now(),
        ]);
    }

    public function test_exam_session_question_is_isolated_per_tenant(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $course = $this->existingCourse('UBI');
        $module = $this->existingModule('UBI');
        [$question, $revision] = $this->anyPublishedQuestionIn($module);
        $reviewer = $this->createTenantUser($tenantA, 'admin');

        $ruleSet = $this->onAdmin(function () use ($course, $reviewer) {
            $ruleSet = ExamRuleSet::create([
                'course_id' => $course->id,
                'version' => 'test-'.uniqid(),
                'status' => 'published',
                'verified_by' => $reviewer->id,
                'verified_at' => now(),
            ]);
            $this->createdRuleSetIds[] = $ruleSet->id;

            return $ruleSet;
        });

        $sessionB = $this->onAdmin(function () use ($tenantB, $course, $ruleSet, $question, $revision) {
            $session = ExamSession::create([
                'tenant_id' => $tenantB->id,
                'user_id' => $this->createTenantUser($tenantB, 'learner')->id,
                'course_id' => $course->id,
                'rule_set_id' => $ruleSet->id,
                'status' => 'running',
                'started_at' => now(),
            ]);

            ExamSessionQuestion::create([
                'tenant_id' => $tenantB->id,
                'exam_session_id' => $session->id,
                'question_id' => $question->id,
                'revision_id' => $revision->id,
                'position' => 1,
            ]);

            return $session;
        });

        app(TenantContext::class)->set($tenantA);

        $visible = DB::table('exam_session_question')->where('exam_session_id', $sessionB->id)->get();

        $this->assertCount(0, $visible);
    }
}
