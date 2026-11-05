<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\TwoFactorResetBySupport;
use App\Support\Totp;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Support-Werkzeuge im Superadmin: 2FA zurücksetzen, Mitgliedschaften, Konto löschen. */
class SuperadminUserSupportTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function fresh(User $user): ?User
    {
        return $this->onAdmin(fn () => User::find($user->id));
    }

    public function test_superadmin_resets_two_factor_and_the_person_is_notified(): void
    {
        Notification::fake();
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'admin');
        $this->onAdmin(fn () => User::findOrFail($user->id)->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['x']])->save());

        $this->actingAs($superadmin)->get("/superadmin/users/{$user->id}")->assertSee('2FA zurücksetzen');
        $this->actingAs($superadmin)->post("/superadmin/users/{$user->id}/two-factor/reset")->assertSessionHas('status');

        $this->assertFalse($this->fresh($user)->hasTwoFactor());
        $this->assertNull($this->fresh($user)->two_factor_secret);
        Notification::assertSentTo($user, TwoFactorResetBySupport::class);
        $this->assertTrue($this->onAdmin(fn () => AuditLog::where('action', 'superadmin.user.two_factor_reset')->where('entity_id', $user->id)->exists()));
    }

    public function test_membership_role_and_status_can_be_changed_but_the_last_owner_stays(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $membership = fn (User $u) => $this->onAdmin(fn () => TenantUser::where('tenant_id', $tenant->id)->where('user_id', $u->id)->first());

        $this->actingAs($superadmin)->patch("/superadmin/users/{$learner->id}/memberships/{$tenant->id}", ['role' => 'instructor', 'status' => 'suspended'])->assertSessionHasNoErrors();
        $this->assertSame(['instructor', 'suspended'], [$membership($learner)->role, $membership($learner)->status]);

        $this->actingAs($superadmin)->patch("/superadmin/users/{$owner->id}/memberships/{$tenant->id}", ['role' => 'admin', 'status' => 'active'])->assertSessionHasErrors('membership');
        $this->assertSame('owner', $membership($owner)->role);
        $this->actingAs($superadmin)->delete("/superadmin/users/{$owner->id}/memberships/{$tenant->id}")->assertSessionHasErrors('membership');
    }

    public function test_removing_a_membership_deletes_the_school_data_and_the_last_one_the_account(): void
    {
        $superadmin = $this->createSuperAdmin();
        $schoolA = $this->createTestTenant('Schule A');
        $schoolB = $this->createTestTenant('Schule B');
        $user = $this->createTenantUser($schoolA, 'learner');
        $this->onAdmin(fn () => TenantUser::create(['tenant_id' => $schoolB->id, 'user_id' => $user->id, 'role' => 'learner', 'status' => 'active']));
        $this->grantEntitlement($schoolA, $user, $this->existingCourse('SRC'));

        $this->actingAs($superadmin)->delete("/superadmin/users/{$user->id}/memberships/{$schoolA->id}")->assertRedirect();
        $this->onAdmin(function () use ($user, $schoolA) {
            $this->assertNotNull(User::find($user->id));
            $this->assertSame(0, Entitlement::where('tenant_id', $schoolA->id)->where('user_id', $user->id)->count());
        });

        $this->actingAs($superadmin)->delete("/superadmin/users/{$user->id}/memberships/{$schoolB->id}")->assertRedirect(route('superadmin.users.index'));
        $this->assertNull($this->fresh($user));
    }

    public function test_deleting_an_account_requires_the_email_and_spares_superadmins_and_sole_owners(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->grantEntitlement($tenant, $learner, $this->existingCourse('SRC'));
        $otherAdmin = $this->createSuperAdmin();

        $this->actingAs($superadmin)->delete("/superadmin/users/{$learner->id}", ['confirm_email' => 'falsch@example.test'])->assertSessionHasErrors('confirm_email');
        $this->actingAs($superadmin)->delete("/superadmin/users/{$owner->id}", ['confirm_email' => $owner->email])->assertSessionHasErrors('confirm_email');
        $this->actingAs($superadmin)->delete("/superadmin/users/{$otherAdmin->id}", ['confirm_email' => $otherAdmin->email])->assertSessionHasErrors('confirm_email');
        $this->assertNotNull($this->fresh($owner));

        $this->actingAs($superadmin)->delete("/superadmin/users/{$learner->id}", ['confirm_email' => $learner->email])->assertRedirect(route('superadmin.users.index'));
        $this->assertNull($this->fresh($learner));
        $this->onAdmin(fn () => $this->assertSame(0, Entitlement::where('user_id', $learner->id)->count()));

        // Konto ohne Mitgliedschaft.
        $orphan = $this->onAdmin(fn () => User::create(['email' => 'ohne-schule-'.uniqid().'@example.test', 'password' => bcrypt('x'), 'status' => 'active']));
        $this->actingAs($superadmin)->delete("/superadmin/users/{$orphan->id}", ['confirm_email' => $orphan->email])->assertRedirect();
        $this->assertNull($this->fresh($orphan));
    }
}
