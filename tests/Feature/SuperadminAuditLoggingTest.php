<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview S4: Sperren/Entsperren einer Bootsschule oder eines
 * Kontos, das Erteilen/Entziehen von is_superadmin, die Rollenzuweisung
 * über die Superadmin-Nutzerseite und das Erzeugen von Coupon-Codes
 * schrieben bisher keine AuditLog-Zeile -- im Unterschied zu den längst
 * bestehenden AuditLog::record()-Aufrufen für Bootsschul-Admin-Aktionen
 * (z. B. entitlement.*, coupon.redeem, participant.update).
 *
 * Bug-Review (Superadmin-Audit-Lücken): dieselbe Lücke bestand weiterhin
 * beim Anlegen eines Nutzers/einer Bootsschule, beim Export von
 * Coupon-Codes und bei einer vom Superadmin selbst geänderten
 * E-Mail-Adresse. Erzeugte Codes stehen außerdem nicht mehr im Klartext
 * im after_data-JSON (siehe AuditLogDescriptionTest für die zugehörige
 * Anzeige-Seite im "Letzte Aktivitäten"-Feed der Bootsschule).
 */
class SuperadminAuditLoggingTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCouponCodes = [];

    // Bug-Review T9: die meisten AuditLog::record()-Aufrufe in den
    // Superadmin-Controllern protokollieren bewusst mit tenant_id=null
    // (plattformweite Aktion, siehe z. B. UserController::update() für
    // superadmin.user.update) oder zeigen auf einen hier manuell -- nicht
    // über $createdTenantIds/$createdUserIds -- gelöschten Tenant/Nutzer.
    // cleanUpCreatedTenants() löscht AuditLog-Zeilen aber ausschließlich
    // über tenant_id -- jede so protokollierte Zeile blieb deshalb nach
    // jedem Testlauf dauerhaft (verwaist, entity_id zeigt ins Leere)
    // bestehen.
    private array $createdAuditLogIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdCouponCodes as $code) {
                Coupon::where('code', $code)->delete();
            }
        });

        $this->cleanUpCreatedAuditLogs();
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    private function cleanUpCreatedAuditLogs(): void
    {
        $this->onAdmin(function () {
            AuditLog::whereIn('id', $this->createdAuditLogIds)->delete();
        });

        $this->createdAuditLogIds = [];
    }

    public function test_suspending_a_tenant_is_logged(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();

        $this->actingAs($superadmin)->patch("/superadmin/tenants/{$tenant->id}", [
            'name' => $tenant->name,
            'status' => 'suspended',
        ])->assertRedirect();

        $this->onAdmin(function () use ($superadmin, $tenant) {
            $log = AuditLog::where('action', 'superadmin.tenant.update')
                ->where('entity_id', $tenant->id)->first();

            $this->assertNotNull($log);
            $this->createdAuditLogIds[] = $log->id;
            $this->assertSame($superadmin->id, $log->actor_user_id);
            $this->assertSame('active', $log->before_data['status']);
            $this->assertSame('suspended', $log->after_data['status']);
        });
    }

    public function test_suspending_a_user_is_logged(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $this->actingAs($superadmin)->patch("/superadmin/users/{$user->id}", [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
            'status' => 'suspended',
        ])->assertRedirect();

        $this->onAdmin(function () use ($superadmin, $user) {
            $log = AuditLog::where('action', 'superadmin.user.update')
                ->where('entity_id', $user->id)->first();

            $this->assertNotNull($log);
            $this->createdAuditLogIds[] = $log->id;
            $this->assertSame($superadmin->id, $log->actor_user_id);
            $this->assertSame('active', $log->before_data['status']);
            $this->assertSame('suspended', $log->after_data['status']);
        });
    }

    public function test_granting_is_superadmin_is_logged(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $this->actingAs($superadmin)->patch("/superadmin/users/{$user->id}", [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
            'status' => 'active',
            'is_superadmin' => '1',
        ])->assertRedirect();

        $this->onAdmin(function () use ($superadmin, $user) {
            $log = AuditLog::where('action', 'superadmin.user.update')
                ->where('entity_id', $user->id)->first();

            $this->assertNotNull($log);
            $this->createdAuditLogIds[] = $log->id;
            $this->assertSame($superadmin->id, $log->actor_user_id);
            $this->assertFalse($log->before_data['is_superadmin']);
            $this->assertTrue($log->after_data['is_superadmin']);
        });
    }

    public function test_assigning_a_tenant_role_is_logged(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $this->actingAs($superadmin)->post("/superadmin/users/{$user->id}/memberships", [
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ])->assertRedirect();

        $this->onAdmin(function () use ($superadmin, $tenant, $user) {
            $log = AuditLog::where('action', 'superadmin.user.membership_update')
                ->where('tenant_id', $tenant->id)->where('entity_id', $user->id)->first();

            $this->assertNotNull($log);
            $this->createdAuditLogIds[] = $log->id;
            $this->assertSame($superadmin->id, $log->actor_user_id);
            $this->assertSame('learner', $log->before_data['role']);
            $this->assertSame('owner', $log->after_data['role']);
        });
    }

    public function test_generating_coupon_codes_is_logged(): void
    {
        $superadmin = $this->createSuperAdmin();
        $course = $this->existingCourse('SRC');

        $response = $this->actingAs($superadmin)->post('/superadmin/coupons', [
            'target' => 'course:'.$course->id,
            'quantity' => 2,
        ]);

        $response->assertRedirect();

        $this->onAdmin(function () use ($superadmin, $course) {
            $this->createdCouponCodes = Coupon::where('course_id', $course->id)->pluck('code')->all();

            $log = AuditLog::where('action', 'superadmin.coupon.create')->latest('id')->first();

            $this->assertNotNull($log);
            $this->createdAuditLogIds[] = $log->id;
            $this->assertSame($superadmin->id, $log->actor_user_id);
            $this->assertSame(2, $log->after_data['quantity']);
            // Bug-Review (Codes im Klartext im Log): die erzeugten Codes
            // selbst dürfen nicht mehr im after_data-JSON stehen.
            $this->assertArrayNotHasKey('codes', $log->after_data);
        });
    }

    public function test_creating_a_user_is_logged(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->post('/superadmin/users', [
            'first_name' => 'Test',
            'last_name' => 'Nutzer',
            'email' => 'test-user-'.uniqid().'@example.test',
        ]);

        $response->assertRedirect();

        $this->onAdmin(function () use ($superadmin) {
            $user = User::where('email', 'like', 'test-user-%')->latest('created_at')->first();

            $log = AuditLog::where('action', 'superadmin.user.create')->where('entity_id', $user->id)->first();

            $this->assertNotNull($log);
            $this->createdAuditLogIds[] = $log->id;
            $this->assertSame($superadmin->id, $log->actor_user_id);
            $this->assertSame($user->email, $log->after_data['email']);

            // Bug-Review T9: UserController::store() schickt für den neuen
            // Nutzer einen Password::sendResetLink() -- password_reset_tokens
            // hat keinen Fremdschlüssel (nur per E-Mail-String geschlüsselt,
            // siehe cleanUpCreatedUsers()) und wurde hier nie geräumt, weil
            // $user über $createdUserIds nie nachverfolgt wird.
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->delete();
        });
    }

    public function test_creating_a_tenant_is_logged(): void
    {
        $superadmin = $this->createSuperAdmin();
        $slug = 'test-schule-'.uniqid();

        $response = $this->actingAs($superadmin)->post('/superadmin/tenants', [
            'slug' => $slug,
            'name' => 'Test Schule',
            'status' => 'trial',
            'admin_first_name' => 'Admin',
            'admin_last_name' => 'Person',
            'admin_email' => 'test-admin-'.uniqid().'@example.test',
        ]);

        $response->assertRedirect();

        $this->onAdmin(function () use ($superadmin, $slug) {
            $tenant = Tenant::where('slug', $slug)->firstOrFail();

            $log = AuditLog::where('action', 'superadmin.tenant.create')->where('entity_id', $tenant->id)->first();

            $this->assertNotNull($log);
            $this->createdAuditLogIds[] = $log->id;
            $this->assertSame($superadmin->id, $log->actor_user_id);
            $this->assertSame($slug, $log->after_data['slug']);

            // Bug-Review T9: TenantController::store() schickt für den neu
            // angelegten Bootsschul-Admin ebenfalls einen
            // Password::sendResetLink() -- siehe ausführlichen Kommentar in
            // test_creating_a_user_is_logged() oben.
            DB::table('password_reset_tokens')->where('email', $log->after_data['admin_email'])->delete();

            $admin = User::where('email', $log->after_data['admin_email'])->first();
            $tenant->delete();
            $admin?->delete();
        });
    }

    public function test_exporting_coupon_codes_is_logged_without_the_codes_themselves(): void
    {
        $superadmin = $this->createSuperAdmin();
        $course = $this->existingCourse('SRC');
        $code = 'E2E-AUDIT-EXPORT-'.uniqid();
        $this->createdCouponCodes[] = $code;
        $coupon = $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id]));

        $response = $this->actingAs($superadmin)->post('/superadmin/coupons/export', [
            'select_all' => '0',
            'ids' => [$coupon->id],
        ]);

        $response->assertOk();

        $this->onAdmin(function () use ($superadmin) {
            $log = AuditLog::where('action', 'superadmin.coupon.export')->latest('id')->first();

            $this->assertNotNull($log);
            $this->createdAuditLogIds[] = $log->id;
            $this->assertSame($superadmin->id, $log->actor_user_id);
            $this->assertSame(1, $log->after_data['count']);
            $this->assertArrayNotHasKey('codes', $log->after_data);
        });
    }

    public function test_changing_a_users_email_as_superadmin_is_logged(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $newEmail = 'changed-'.uniqid().'@example.test';

        $this->actingAs($superadmin)->patch("/superadmin/users/{$user->id}", [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $newEmail,
            'status' => 'active',
        ])->assertRedirect();

        $this->onAdmin(function () use ($superadmin, $user, $newEmail) {
            $log = AuditLog::where('action', 'superadmin.user.update')
                ->where('entity_id', $user->id)->first();

            $this->assertNotNull($log);
            $this->createdAuditLogIds[] = $log->id;
            $this->assertSame($superadmin->id, $log->actor_user_id);
            $this->assertSame($user->email, $log->before_data['email']);
            $this->assertSame($newEmail, $log->after_data['email']);
        });
    }

    public function test_updating_platform_settings_is_logged(): void
    {
        $superadmin = $this->createSuperAdmin();
        // Bug-Review Betrieb B3: maintenance_mode ist eine global geteilte
        // Singleton-Zeile (platform_setting, id = 1) -- dieser Test ändert
        // ihn bewusst nicht, um die Test-DB nicht dauerhaft in den
        // Wartungsmodus zu versetzen (siehe MaintenanceModeTest).
        $originalSiteName = $this->onAdmin(fn () => PlatformSetting::current()->site_name);
        $newSiteName = 'Geänderter Seitenname '.uniqid();

        $this->actingAs($superadmin)->patch('/superadmin/settings', [
            'site_name' => $newSiteName,
            'support_email' => 'support-'.uniqid().'@example.test',
        ])->assertRedirect();

        $this->onAdmin(function () use ($superadmin, $originalSiteName, $newSiteName) {
            $log = AuditLog::where('action', 'superadmin.settings.update')->latest('created_at')->first();

            $this->assertNotNull($log);
            $this->createdAuditLogIds[] = $log->id;
            $this->assertSame($superadmin->id, $log->actor_user_id);
            $this->assertSame($originalSiteName, $log->before_data['site_name']);
            $this->assertSame($newSiteName, $log->after_data['site_name']);

            PlatformSetting::current()->update(['site_name' => $originalSiteName, 'support_email' => null]);
        });
    }

    /**
     * Bug-Review T9: dieselbe Art Lücke wie TestIsolationCleanupTest für
     * tenant_id/password_reset_tokens, hier für die $createdAuditLogIds-
     * Nachverfolgung oben -- ohne sie bliebe z. B. die Zeile aus
     * test_updating_platform_settings_is_logged() (tenant_id=null) nach
     * jedem Testlauf dauerhaft verwaist stehen, weil
     * cleanUpCreatedTenants() nur über tenant_id löscht.
     */
    public function test_cleaning_up_the_test_also_removes_its_platform_wide_audit_log_entries(): void
    {
        $superadmin = $this->createSuperAdmin();
        $marker = 'test.isolation.audit.'.uniqid();

        $logId = $this->onAdmin(function () use ($superadmin, $marker) {
            // tenant_id bewusst null -- genau das Muster von
            // superadmin.settings.update/superadmin.user.update/
            // superadmin.coupon.create/-export, das $createdTenantIds nie
            // erfasst.
            $log = AuditLog::record(null, $superadmin->id, $marker, 'test_entity', null);
            $this->assertDatabaseHas('audit_log', ['action' => $marker]);

            return $log->id;
        });

        $this->createdAuditLogIds[] = $logId;
        $this->cleanUpCreatedAuditLogs();

        $this->onAdmin(function () use ($marker) {
            $this->assertDatabaseMissing('audit_log', ['action' => $marker]);
        });
    }
}
