<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\TenantCourseDisabled;
use App\Models\User;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview M3: Registrierung verriet über die "unique"-
 * Validierungsmeldung platformweit, ob eine E-Mail-Adresse schon
 * existiert, und EntitlementController::store ließ sich mit einer
 * beliebigen User-UUID (auch aus einer anderen Bootsschule) sowie für
 * nicht angebotene Kurse aufrufen.
 */
class CrossTenantAccountAccessTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCodes = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        // Coupon.tenant_id ist nullOnDelete, nicht kaskadierend -- ohne
        // dieses Aufräumen bliebe der hier angelegte Code als verwaister
        // (tenant_id=NULL) Datensatz dauerhaft stehen.
        $this->onAdmin(function () {
            foreach ($this->createdCodes as $code) {
                Coupon::where('code', $code)->delete();
            }
        });

        parent::tearDown();
    }

    public function test_registering_with_an_already_used_email_does_not_leak_a_field_level_uniqueness_error(): void
    {
        $tenant = $this->createTestTenant();
        $existing = $this->createTenantUser($tenant, 'learner');

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/register', [
            'name' => 'Angreifer',
            'email' => $existing->email,
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ]);

        $response->assertSessionDoesntHaveErrors('email');
        $this->assertGuest();
        $this->assertSame(1, User::where('email', $existing->email)->count());
    }

    public function test_registering_with_a_new_email_still_works_as_before(): void
    {
        $tenant = $this->createTestTenant();
        $email = 'brandneu-'.uniqid().'@example.test';

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->post('/register', [
            'name' => 'Neuer Nutzer',
            'email' => $email,
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertGuest();

        $this->onAdmin(function () use ($email) {
            $user = User::where('email', $email)->first();
            $this->assertNotNull($user);
            $this->createdUserIds[] = $user->id;
        });
    }

    public function test_entitlement_cannot_be_granted_to_a_user_who_is_not_a_member_of_the_tenant(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $adminA = $this->createTenantUser($tenantA, 'owner');
        $outsiderOfB = $this->createTenantUser($tenantB, 'learner');
        $course = $this->existingCourse('SRC');

        $response = $this->actingAsInTenant($adminA, $tenantA)->post('/admin/entitlements', [
            'user_id' => $outsiderOfB->id,
            'course_id' => $course->id,
        ]);

        $response->assertNotFound();
        $this->onAdmin(fn () => $this->assertDatabaseMissing('entitlement', [
            'tenant_id' => $tenantA->id,
            'user_id' => $outsiderOfB->id,
            'course_id' => $course->id,
        ]));
    }

    public function test_entitlement_cannot_be_granted_for_a_course_disabled_for_the_tenant(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->onAdmin(fn () => TenantCourseDisabled::create(['tenant_id' => $tenant->id, 'course_id' => $course->id]));

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', [
            'user_id' => $learner->id,
            'course_id' => $course->id,
        ]);

        $response->assertNotFound();
        $this->onAdmin(fn () => $this->assertDatabaseMissing('entitlement', [
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'course_id' => $course->id,
        ]));
    }

    public function test_entitlement_can_still_be_granted_to_a_real_member_for_an_offered_course(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $code = 'TEST-CROSS-ENT-'.uniqid();
        $this->createdCodes[] = $code;
        $this->onAdmin(fn () => Coupon::create(['code' => $code, 'course_id' => $course->id, 'tenant_id' => $tenant->id]));

        $response = $this->actingAsInTenant($admin, $tenant)->post('/admin/entitlements', [
            'user_id' => $learner->id,
            'course_id' => $course->id,
        ]);

        $response->assertRedirect();
        $this->onAdmin(fn () => $this->assertDatabaseHas('entitlement', [
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'course_id' => $course->id,
        ]));
    }
}
