<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\User;
use App\Models\WillkommensboxOrder;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * "Letzte Aktivitäten" auf dem Bootsschul-Dashboard (admin/tabs/dashboard.
 * blade.php) zeigt jetzt lesbare deutsche Sätze statt des rohen
 * Aktionscodes (siehe AuditLog::description()) -- wer hat was gemacht,
 * mit dem betroffenen Teilnehmer wo zutreffend.
 */
class AuditLogDescriptionTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCodes = [];

    private array $createdTemplateIds = [];

    private array $createdCourseIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            foreach ($this->createdCodes as $code) {
                Coupon::where('code', $code)->delete();
            }
            foreach ($this->createdTemplateIds as $id) {
                PraxisFormTemplate::where('id', $id)->delete();
            }
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    public function test_willkommensbox_shipping_is_described_in_german(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner', ['display_name' => 'Anna Meyer']);
        $learner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Max Mustermann']);
        $order = $this->onAdmin(fn () => WillkommensboxOrder::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'street' => 'Teststraße 1',
            'postal_code' => '12345',
            'city' => 'Teststadt',
            'country' => 'Deutschland',
        ]));

        $this->actingAsInTenant($admin, $tenant)->patch("/admin/willkommensbox-orders/{$order->id}/ship", []);

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Anna Meyer hat die Willkommensbox von Max Mustermann als versendet markiert.');
    }

    public function test_participant_address_update_is_described_in_german(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner', ['display_name' => 'Anna Meyer']);
        $learner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Max Mustermann']);

        $this->actingAsInTenant($admin, $tenant)->patch("/admin/participants/{$learner->id}", ['city' => 'Hamburg']);

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Anna Meyer hat die Adresse von Max Mustermann aktualisiert.');
    }

    public function test_participant_invite_is_described_in_german(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner', ['display_name' => 'Anna Meyer']);
        $email = 'e2e-audit-invite-'.uniqid().'@example.test';

        $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', [
            'name' => 'E2E Audit Teilnehmer',
            'email' => $email,
        ]);

        // ParticipantController::invite legt den Nutzer selbst an -- für
        // die Aufräumung durch cleanUpCreatedTenantsAndUsers() in tearDown()
        // muss die id daher hier nachträglich erfasst werden.
        $invitedUserId = $this->onAdmin(fn () => User::where('email', $email)->firstOrFail()->id);
        $this->createdUserIds[] = $invitedUserId;

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Anna Meyer hat E2E Audit Teilnehmer eingeladen.');
    }

    public function test_entitlement_create_and_revoke_are_described_in_german(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner', ['display_name' => 'Anna Meyer']);
        $learner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Max Mustermann']);
        $course = $this->existingCourse('SRC');
        $code = 'TEST-AUDIT-ENT-'.uniqid();
        $this->createdCodes[] = $code;
        $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', [
            'user_id' => $learner->id,
            'course_id' => $course->id,
        ]);
        $entitlement = $this->onAdmin(fn () => Entitlement::where('user_id', $learner->id)->where('course_id', $course->id)->first());

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));
        $response->assertOk();
        $response->assertSee('Anna Meyer hat den Kurs "'.$course->name.'" für Max Mustermann freigeschaltet.');

        $this->actingAsInTenant($admin, $tenant)->patch("/admin/entitlements/{$entitlement->id}", ['action' => 'revoke']);

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));
        $response->assertOk();
        $response->assertSee('Anna Meyer hat den Kurs "'.$course->name.'" für Max Mustermann widerrufen.');
    }

    public function test_coupon_self_redemption_is_described_without_a_separate_actor(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner', ['display_name' => 'Anna Meyer']);
        $learner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Max Mustermann']);
        $course = $this->existingCourse('SRC');
        $code = 'E2E-AUDIT-SELF';
        $this->createdCodes[] = $code;
        $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        $this->actingAsInTenant($learner, $tenant)->post('/coupons/redeem', ['code' => $code]);

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Max Mustermann hat den Code "'.$code.'" ('.$course->name.') eingelöst.');
    }

    public function test_coupon_assigned_by_admin_is_described_with_actor_and_learner(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner', ['display_name' => 'Anna Meyer']);
        $learner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Max Mustermann']);
        $course = $this->existingCourse('SRC');
        $code = 'E2E-AUDIT-ADMIN';
        $this->createdCodes[] = $code;
        $coupon = $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        $this->actingAsInTenant($admin, $tenant)->post("/admin/coupons/{$coupon->id}/assign", ['user_id' => $learner->id]);

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Anna Meyer hat den Code "'.$code.'" ('.$course->name.') für Max Mustermann eingelöst.');
    }

    public function test_praxis_appointment_proposal_is_described_in_german(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner', ['display_name' => 'Anna Meyer']);
        $learner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Max Mustermann']);

        $course = $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-AUDIT-APPT-'.uniqid(),
                'name' => 'Test-Kurs für Audit-Log',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            return $course;
        });
        $this->grantEntitlement($tenant, $learner, $course);
        $template = $this->onAdmin(function () use ($course) {
            $template = PraxisFormTemplate::create([
                'title' => 'E2E Audit-Formular',
                'min_progress_percent' => 0,
                'course_ids' => [$course->id],
                'fields' => [],
                'active' => true,
            ]);
            $this->createdTemplateIds[] = $template->id;

            return $template;
        });
        $submission = $this->onAdmin(fn () => PraxisFormSubmission::create([
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $learner->id,
            'praxis_form_template_id' => $template->id,
            'data' => [],
        ]));

        $this->actingAsInTenant($admin, $tenant)->post(
            "/admin/praxis-form-submissions/{$submission->id}/exam-appointments",
            ['exam_date' => '2027-06-01 09:00', 'slot' => 0]
        );

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Anna Meyer hat Max Mustermann "Prüfungstermin" vorgeschlagen.');
    }

    public function test_branding_update_is_described_in_german(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner', ['display_name' => 'Anna Meyer']);

        $this->actingAsInTenant($admin, $tenant)->patch('/admin/branding', ['exam_readiness_threshold_percent' => 80]);

        $response = $this->actingAsInTenant($admin, $tenant)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Anna Meyer hat das Branding aktualisiert.');
    }
}
