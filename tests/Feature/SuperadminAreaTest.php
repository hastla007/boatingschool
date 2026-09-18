<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Plattformweiter Superadmin-Bereich: mandantenunabhängiger Zugriff auf
 * Bootsschulen/Nutzer/Kurse/Einstellungen sowie das Coupon-System (Erzeugung,
 * Batch-Generierung, Einlösung inkl. Mandanten-Isolation).
 */
class SuperadminAreaTest extends TestCase
{
    use InteractsWithTenants;

    /** @var array<int, string> IDs für global angelegte, nicht mandantengebundene Fixtures (Kurs/Fragen). */
    private array $createdCourseIds = [];

    private array $createdQuestionIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdQuestionIds as $id) {
                ContentAnswer::whereIn('revision_id', ContentQuestionRevision::where('question_id', $id)->pluck('id'))->delete();
                ContentQuestionRevision::where('question_id', $id)->delete();
                DB::table('module_content')->where('question_id', $id)->delete();
                ContentQuestion::where('id', $id)->delete();
            }

            foreach ($this->createdCourseIds as $id) {
                $course = CourseDefinition::withoutGlobalScopes()->find($id);
                $course?->modules()->detach();
                $course?->delete();
            }
        });

        // InteractsWithTenants::tearDown() ist durch diese Methode verdeckt
        // (Trait-Methoden werden von einer gleichnamigen Klassenmethode
        // überschrieben, nicht automatisch mitaufgerufen); Tenant-/Nutzer-
        // Aufräumung deshalb explizit über die Helper-Methode anstoßen.
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_a_regular_tenant_user_cannot_access_the_superadmin_area(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->get('/superadmin');

        $response->assertForbidden();
    }

    public function test_login_and_the_superadmin_area_are_reachable_without_a_resolved_tenant(): void
    {
        $superadmin = $this->createSuperAdmin();

        $this->get('/login')->assertOk();

        $response = $this->actingAs($superadmin)->get('/superadmin');

        $response->assertOk();
        $response->assertSee('Superadmin-Dashboard');
    }

    public function test_login_redirects_a_superadmin_straight_to_the_superadmin_dashboard(): void
    {
        $superadmin = $this->createSuperAdmin(['email' => 'super-login-test@platform.test']);

        $response = $this->post('/login', [
            'email' => 'super-login-test@platform.test',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('superadmin.dashboard', absolute: false));
    }

    public function test_superadmin_can_create_a_tenant_with_an_owner_admin(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->post('/superadmin/tenants', [
            'slug' => 'e2e-neue-schule',
            'name' => 'E2E Neue Schule',
            'status' => 'active',
            'admin_first_name' => 'Test',
            'admin_last_name' => 'Admin',
            'admin_email' => 'e2e-neue-schule-admin@example.test',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tenant', ['slug' => 'e2e-neue-schule', 'name' => 'E2E Neue Schule']);
        $this->assertDatabaseHas('app_user', ['email' => 'e2e-neue-schule-admin@example.test']);

        $this->onAdmin(function () {
            $tenant = \App\Models\Tenant::where('slug', 'e2e-neue-schule')->first();
            $this->createdTenantIds[] = $tenant->id;
            $admin = \App\Models\User::where('email', 'e2e-neue-schule-admin@example.test')->first();
            $this->createdUserIds[] = $admin->id;

            $this->assertNotNull($tenant->branding, 'Beim Anlegen sollte automatisch ein Branding-Datensatz erzeugt werden.');
            $this->assertDatabaseHas('tenant_user', ['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role' => 'owner']);
        });
    }

    public function test_superadmin_can_create_a_user_and_assign_a_tenant_membership(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();

        $response = $this->actingAs($superadmin)->post('/superadmin/users', [
            'first_name' => 'Nina',
            'last_name' => 'Beispiel',
            'email' => 'nina-beispiel@example.test',
            'tenant_id' => $tenant->id,
            'role' => 'learner',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('app_user', ['email' => 'nina-beispiel@example.test']);

        $this->onAdmin(function () use ($tenant) {
            $user = \App\Models\User::where('email', 'nina-beispiel@example.test')->first();
            $this->createdUserIds[] = $user->id;
            $this->assertDatabaseHas('tenant_user', ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'learner']);
        });
    }

    public function test_superadmin_sees_a_users_course_results_across_tenants(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAs($superadmin)->get("/superadmin/users/{$learner->id}");

        $response->assertOk();
        $response->assertSee($course->name);
    }

    public function test_superadmin_can_create_a_course_attach_a_module_and_add_a_question_with_answers(): void
    {
        $superadmin = $this->createSuperAdmin();
        $module = $this->existingModule('SRC');

        $courseResponse = $this->actingAs($superadmin)->post('/superadmin/courses', [
            'code' => 'E2E-TEST-COURSE',
            'name' => 'E2E Test Kurs',
            'course_type' => 'full',
            'status' => 'published',
        ]);
        $courseResponse->assertRedirect();

        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'E2E-TEST-COURSE')->firstOrFail();
        $this->createdCourseIds[] = $course->id;

        $attachResponse = $this->actingAs($superadmin)->post("/superadmin/courses/{$course->id}/modules", [
            'module_id' => $module->id,
        ]);
        $attachResponse->assertRedirect();
        $this->assertDatabaseHas('course_module', ['course_id' => $course->id, 'module_id' => $module->id]);

        $questionResponse = $this->actingAs($superadmin)->post("/superadmin/modules/{$module->id}/questions", [
            'question_text' => 'E2E: Was bedeutet Steuerbord?',
            'topic' => 'Navigation',
            'answers' => [
                'A' => 'Rechte Schiffsseite',
                'B' => 'Linke Schiffsseite',
                'C' => 'Der Bug',
                'D' => 'Das Heck',
            ],
            'correct' => 'A',
        ]);
        $questionResponse->assertRedirect();

        $revision = ContentQuestionRevision::where('question_text', 'E2E: Was bedeutet Steuerbord?')->firstOrFail();
        $this->createdQuestionIds[] = $revision->question_id;

        $this->assertSame(1, $revision->revision_no);
        $this->assertSame('published', $revision->editorial_status);
        $this->assertDatabaseHas('content_answer', ['revision_id' => $revision->id, 'answer_key' => 'A', 'is_correct' => true]);
        $this->assertDatabaseHas('module_content', ['module_id' => $module->id, 'question_id' => $revision->question_id]);
    }

    public function test_editing_a_question_creates_a_new_revision_and_deprecates_the_previous_one(): void
    {
        $superadmin = $this->createSuperAdmin();
        $module = $this->existingModule('SRC');

        $this->actingAs($superadmin)->post("/superadmin/modules/{$module->id}/questions", [
            'question_text' => 'E2E: Revisionstest Frage',
            'answers' => ['A' => 'Antwort A', 'B' => 'Antwort B', 'C' => 'Antwort C', 'D' => 'Antwort D'],
            'correct' => 'A',
        ]);

        $firstRevision = ContentQuestionRevision::where('question_text', 'E2E: Revisionstest Frage')->firstOrFail();
        $this->createdQuestionIds[] = $firstRevision->question_id;
        $question = ContentQuestion::find($firstRevision->question_id);

        $updateResponse = $this->actingAs($superadmin)->patch("/superadmin/questions/{$question->id}", [
            'question_text' => 'E2E: Revisionstest Frage (bearbeitet)',
            'answers' => ['A' => 'Antwort A neu', 'B' => 'Antwort B', 'C' => 'Antwort C', 'D' => 'Antwort D'],
            'correct' => 'B',
        ]);
        $updateResponse->assertRedirect();

        $firstRevision->refresh();
        $this->assertSame('deprecated', $firstRevision->editorial_status);

        $latest = $question->publishedRevision();
        $this->assertNotNull($latest);
        $this->assertSame(2, $latest->revision_no);
        $this->assertSame('E2E: Revisionstest Frage (bearbeitet)', $latest->question_text);
        $this->assertDatabaseHas('content_answer', ['revision_id' => $latest->id, 'answer_key' => 'B', 'is_correct' => true]);
    }

    public function test_superadmin_can_update_platform_settings(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->patch('/superadmin/settings', [
            'site_name' => 'Neue Plattform GmbH',
            'support_email' => 'support@example.test',
            'maintenance_mode' => '1',
            'maintenance_message' => 'Wartungsarbeiten laufen.',
        ]);

        $response->assertRedirect();

        $settings = PlatformSetting::current();
        $this->assertSame('Neue Plattform GmbH', $settings->site_name);
        $this->assertTrue((bool) $settings->maintenance_mode);

        // Zustand für nachfolgende Tests zurücksetzen (Singleton-Zeile).
        $this->onAdmin(fn () => $settings->update(['site_name' => 'Bootsführerschein-Lernplattform', 'support_email' => null, 'maintenance_mode' => false, 'maintenance_message' => null]));
    }

    public function test_superadmin_can_generate_a_single_and_a_batch_of_coupons_scoped_to_a_tenant(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $course = $this->existingCourse('SRC');

        $single = $this->actingAs($superadmin)->post('/superadmin/coupons', [
            'course_id' => $course->id,
            'tenant_id' => $tenant->id,
            'quantity' => 1,
        ]);
        $single->assertRedirect();
        $this->assertSame(1, Coupon::where('tenant_id', $tenant->id)->count());

        $batch = $this->actingAs($superadmin)->post('/superadmin/coupons', [
            'course_id' => $course->id,
            'tenant_id' => $tenant->id,
            'quantity' => 5,
            'batch_label' => 'E2E Testbatch',
        ]);
        $batch->assertRedirect();
        $this->assertSame(6, Coupon::where('tenant_id', $tenant->id)->count());
        $this->assertSame(5, Coupon::where('tenant_id', $tenant->id)->where('batch_label', 'E2E Testbatch')->count());

        $index = $this->actingAs($superadmin)->get('/superadmin/coupons?tenant_id='.$tenant->id);
        $index->assertOk();
    }

    public function test_a_tenant_assigned_coupon_can_be_redeemed_by_that_tenants_learner(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $coupon = $this->onAdmin(fn () => Coupon::create([
            'code' => Coupon::generateUniqueCode(),
            'course_id' => $course->id,
            'tenant_id' => $tenant->id,
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', [
            'code' => $coupon->code,
        ]);

        $response->assertRedirect(route('courses.show', $course, absolute: false));
        $this->assertDatabaseHas('entitlement', [
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'course_id' => $course->id,
            'source_type' => 'coupon',
        ]);
        $coupon->refresh();
        $this->assertTrue($coupon->isRedeemed());
        $this->assertSame($tenant->id, $coupon->redeemed_tenant_id);

        // Direkt nach dem Einlösen muss der Kurs ohne Race-Condition sofort zugreifbar sein.
        $courseShow = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}");
        $courseShow->assertOk();
    }

    public function test_a_coupon_assigned_to_one_tenant_is_invisible_and_unredeemable_from_another_tenant(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $learnerB = $this->createTenantUser($tenantB, 'learner');
        $course = $this->existingCourse('SRC');
        $coupon = $this->onAdmin(fn () => Coupon::create([
            'code' => Coupon::generateUniqueCode(),
            'course_id' => $course->id,
            'tenant_id' => $tenantA->id,
        ]));

        $response = $this->actingAsInTenant($learnerB, $tenantB)->post('/coupons/redeem', [
            'code' => $coupon->code,
        ]);

        $response->assertSessionHasErrors('code');
        $this->assertDatabaseMissing('entitlement', ['tenant_id' => $tenantB->id, 'course_id' => $course->id]);

        $coupon->refresh();
        $this->assertFalse($coupon->isRedeemed());
    }

    public function test_an_already_redeemed_coupon_cannot_be_redeemed_again(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $coupon = $this->onAdmin(fn () => Coupon::create([
            'code' => Coupon::generateUniqueCode(),
            'course_id' => $course->id,
            'tenant_id' => null,
        ]));

        $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $coupon->code]);

        $second = $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $coupon->code]);

        $second->assertSessionHasErrors('code');
        $this->assertSame(1, DB::table('entitlement')->where('tenant_id', $tenant->id)->where('course_id', $course->id)->count());
    }

    public function test_an_unassigned_coupon_can_be_redeemed_by_any_tenants_learner(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $coupon = $this->onAdmin(fn () => Coupon::create([
            'code' => Coupon::generateUniqueCode(),
            'course_id' => $course->id,
            'tenant_id' => null,
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', [
            'code' => $coupon->code,
        ]);

        $response->assertRedirect(route('courses.show', $course, absolute: false));
        $this->assertDatabaseHas('entitlement', ['tenant_id' => $tenant->id, 'user_id' => $learner->id, 'course_id' => $course->id]);
    }

    public function test_an_invalid_coupon_code_shows_an_error(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', [
            'code' => 'NOPE-NOPE-NOPE',
        ]);

        $response->assertSessionHasErrors('code');
    }
}
