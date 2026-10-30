<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\ExamRuleSet;
use App\Models\ExamSession;
use App\Models\ExamSessionQuestion;
use App\Models\Product;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    private array $createdProductIds = [];

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

            foreach ($this->createdProductIds as $id) {
                Product::where('id', $id)->delete();
            }
        });

        $this->cleanUpCreatedUsers();

        parent::tearDown();
    }

    public function test_app_role_cannot_write_to_the_global_question_catalog(): void
    {
        $tenant = $this->createTestTenant();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/permission denied for table content_question/');

        // Sicherheitsreview M5: app.current_tenant_id gilt seit SET LOCAL
        // nur innerhalb der Transaktion, in der es gesetzt wurde -- ein
        // bloßer set()-Aufruf ohne Transaktion wäre sofort wieder verfallen.
        $this->asTenant($tenant, function () {
            DB::table('content_question')->insert([
                'id' => (string) Str::uuid(),
                'content_id' => 'HACK-'.uniqid(),
            ]);
        });
    }

    public function test_app_role_cannot_mint_a_free_coupon(): void
    {
        $tenant = $this->createTestTenant();
        $course = $this->existingCourse('SRC');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/permission denied for table coupon/');

        $this->asTenant($tenant, function () use ($course) {
            DB::table('coupon')->insert([
                'id' => (string) Str::uuid(),
                'code' => 'MINTED-'.uniqid(),
                'course_id' => $course->id,
                'tenant_id' => null,
                'is_renewal' => false,
                'created_at' => now(),
            ]);
        });
    }

    public function test_app_role_cannot_delete_audit_log_entries(): void
    {
        $tenant = $this->createTestTenant();
        $id = (string) Str::uuid();

        $this->asTenant($tenant, function () use ($tenant, $id) {
            DB::table('audit_log')->insert([
                'id' => $id,
                'tenant_id' => $tenant->id,
                'action' => 'test.action',
                'entity_type' => 'tenant',
                'entity_id' => $tenant->id,
                'created_at' => now(),
            ]);
        });

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/permission denied for table audit_log/');

        $this->asTenant($tenant, function () use ($id) {
            DB::table('audit_log')->where('id', $id)->delete();
        });
    }

    public function test_app_role_cannot_insert_a_platform_wide_audit_log_entry(): void
    {
        $tenant = $this->createTestTenant();

        $this->expectException(QueryException::class);

        $this->asTenant($tenant, function () use ($tenant) {
            DB::table('audit_log')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => null,
                'action' => 'test.platform_action',
                'entity_type' => 'tenant',
                'entity_id' => $tenant->id,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Bug-Review (App-Rolle darf in tenant schreiben/löschen): "tenant"
     * trägt keine RLS-Policy (es IST die Mandantengrenze) -- bis zu diesem
     * Fix war das pauschale GRANT aus 2024_02_01_000070 der einzige Schutz,
     * und das erlaubte der App-Rolle uneingeschränktes INSERT/UPDATE/
     * DELETE. Tenant::create()/->update()/->delete() laufen ausschließlich
     * im Superadmin-Bereich über die Owner-Verbindung (siehe
     * UseAdminDatabaseConnection), die App-Rolle braucht dieses
     * Schreibrecht nirgends.
     */
    public function test_app_role_cannot_create_a_tenant(): void
    {
        $tenant = $this->createTestTenant();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/permission denied for table tenant/');

        $this->asTenant($tenant, function () {
            DB::table('tenant')->insert([
                'id' => (string) Str::uuid(),
                'name' => 'Gehackte Bootsschule',
                'slug' => 'gehackt-'.uniqid(),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /** Die Kaskade über rund 25 Fremdschlüssel macht ein ungeschütztes DELETE hier besonders riskant. */
    public function test_app_role_cannot_delete_a_tenant(): void
    {
        $tenant = $this->createTestTenant();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/permission denied for table tenant/');

        $this->asTenant($tenant, function () use ($tenant) {
            DB::table('tenant')->where('id', $tenant->id)->delete();
        });
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

    /**
     * Bug-Review (Coupon-Nebentabelle ohne DB-Lock pro Bootsschule):
     * coupon_option_product hatte bislang keine eigene RLS-Policy -- anders
     * als coupon selbst (coupon_isolation) war die Zuordnung der gebündelten
     * Zusatzprodukte zu einem Coupon tabellenweit für jede Bootsschule
     * sichtbar, sobald eine Abfrage sie direkt statt über einen JOIN auf
     * coupon ansprach. Die neue Policy verweist mangels eigener tenant_id
     * auf die Mandantengrenze der referenzierten coupon-Zeile.
     */
    public function test_coupon_option_product_is_isolated_per_tenant(): void
    {
        $tenantB = $this->createTestTenant();
        $course = $this->existingCourse('SRC');

        $couponB = $this->onAdmin(function () use ($tenantB, $course) {
            $product = Product::create(['code' => 'TEST-W11-'.uniqid(), 'name' => 'Testprodukt W11', 'active' => true]);
            $this->createdProductIds[] = $product->id;

            $coupon = Coupon::create([
                'code' => 'TESTW11-'.uniqid(),
                'course_id' => $course->id,
                'is_renewal' => false,
                'tenant_id' => $tenantB->id,
            ]);
            $coupon->optionProducts()->attach($product->id);

            return $coupon;
        });

        $tenantA = $this->createTestTenant();
        app(TenantContext::class)->set($tenantA);

        $visible = DB::table('coupon_option_product')->where('coupon_id', $couponB->id)->get();

        $this->assertCount(0, $visible);
    }

    /**
     * Bug-Review (Datenbank-Absicherung, drei zusätzliche Härtungslücken --
     * Protokollzeilen ohne Schule): audit_log_select_isolation ließ bislang
     * zusätzlich zur eigenen Zeile auch jede Zeile mit tenant_id IS NULL
     * durch (anders als course_definition/coupon ist das bei audit_log aber
     * keine absichtlich geteilte Plattform-Zeile, sondern eine verwaiste
     * Zeile einer gelöschten Schule). Nur das Fehlen dieser Ausnahme ist
     * hier beobachtbar und per Stash verifizierbar -- die begleitende
     * Härtung von app_user_update_isolation (WITH CHECK statt bloßem
     * `true`) und app_user_delete_isolation (USING statt `true`) ändert
     * dagegen nachweislich kein beobachtbares Verhalten: Postgres verlangt
     * für UPDATE/DELETE ohnehin zusätzlich Sichtbarkeit über die
     * SELECT-Policy der Tabelle (siehe AccountErasureService::
     * leaveTenant()), ein Update/Delete auf eine fremde, nicht sichtbare
     * app_user-Zeile betraf schon vor dieser Migration nachweislich 0
     * Zeilen -- ein Test dafür würde unabhängig vom Fix immer grün sein und
     * damit keine echte Regression abdecken.
     */
    public function test_audit_log_rows_without_a_tenant_are_not_visible_from_another_tenants_context(): void
    {
        $tenant = $this->createTestTenant();
        $id = (string) Str::uuid();

        $this->onAdmin(function () use ($id) {
            DB::table('audit_log')->insert([
                'id' => $id,
                'tenant_id' => null,
                'action' => 'test.orphaned_platform_row',
                'entity_type' => 'tenant',
                'entity_id' => (string) Str::uuid(),
                'created_at' => now(),
            ]);
        });

        app(TenantContext::class)->set($tenant);

        $visible = DB::table('audit_log')->where('id', $id)->get();

        $this->assertCount(0, $visible);

        $this->onAdmin(function () use ($id) {
            DB::table('audit_log')->where('id', $id)->delete();
        });
    }
}
