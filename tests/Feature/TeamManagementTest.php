<?php

namespace Tests\Feature;

use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Team der Bootsschule selbst verwalten (Admin\TeamController): Ausbilder
 * und Admins einladen, Rollen ändern, Inhaberschaft übergeben.
 */
class TeamManagementTest extends TestCase
{
    use InteractsWithTenants;

    private array $emails = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            TenantInvitation::whereIn('email', $this->emails)->delete();
            User::whereIn('email', $this->emails)->get()->each(function ($user) {
                TenantUser::where('user_id', $user->id)->delete();
                $user->delete();
            });
        });
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function role($tenant, $user): ?string
    {
        return $this->onAdmin(fn () => TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->value('role'));
    }

    public function test_the_owner_invites_instructors_and_admins_an_admin_only_instructors(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $admin = $this->createTenantUser($tenant, 'admin');
        $this->emails = ['e2e-team-instr@example.test', 'e2e-team-admin@example.test', 'e2e-team-denied@example.test'];

        $this->actingAsInTenant($owner, $tenant)->post('/admin/team/invite', ['name' => 'Ina Ausbilder', 'email' => $this->emails[0], 'role' => 'instructor'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Einladung verschickt.');
        $this->actingAsInTenant($owner, $tenant)->post('/admin/team/invite', ['name' => 'Adam Admin', 'email' => $this->emails[1], 'role' => 'admin'])
            ->assertSessionHasNoErrors();
        $this->actingAsInTenant($admin, $tenant)->post('/admin/team/invite', ['name' => 'Kein Admin', 'email' => $this->emails[2], 'role' => 'admin'])
            ->assertSessionHasErrors('role');

        $this->onAdmin(function () use ($tenant) {
            $roles = User::whereIn('email', $this->emails)->get()->mapWithKeys(fn ($u) => [$u->email => TenantUser::where('tenant_id', $tenant->id)->where('user_id', $u->id)->value('role')]);
            $this->assertSame('instructor', $roles[$this->emails[0]]);
            $this->assertSame('admin', $roles[$this->emails[1]]);
            $this->assertArrayNotHasKey($this->emails[2], $roles->all());
        });

        $this->actingAsInTenant($owner, $tenant)->get('/admin?tab=participants')->assertOk()
            ->assertSee('Ina Ausbilder', false)->assertSee('Inhaberschaft übergeben', false);
    }

    public function test_an_existing_account_gets_an_invitation_with_the_role(): void
    {
        Notification::fake();
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $other = $this->createTestTenant('Andere Schule');
        $existing = $this->createTenantUser($other, 'learner');

        $this->actingAsInTenant($owner, $tenant)->post('/admin/team/invite', ['name' => 'X', 'email' => $existing->email, 'role' => 'instructor'])
            ->assertSessionHas('status', 'Einladung verschickt.');
        $this->onAdmin(fn () => $this->assertSame('instructor', TenantInvitation::where('tenant_id', $tenant->id)->where('email', $existing->email)->value('role')));
        $this->assertNull($this->role($tenant, $existing), 'Mitglied erst nach Annahme');
        $this->emails[] = $existing->email;
    }

    public function test_role_changes_respect_the_hierarchy(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $admin = $this->createTenantUser($tenant, 'admin');
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $learner = $this->createTenantUser($tenant, 'learner');

        // Admin: Lernende <-> Ausbilder ja, Admins nein, sich selbst nein, Inhaber nein.
        $this->actingAsInTenant($admin, $tenant)->patch("/admin/team/{$learner->id}/role", ['role' => 'instructor'])->assertSessionHasNoErrors();
        $this->assertSame('instructor', $this->role($tenant, $learner));
        $this->actingAsInTenant($admin, $tenant)->patch("/admin/team/{$instructor->id}/role", ['role' => 'admin'])->assertForbidden();
        $this->actingAsInTenant($admin, $tenant)->patch("/admin/team/{$admin->id}/role", ['role' => 'learner'])->assertStatus(422);
        $this->actingAsInTenant($admin, $tenant)->patch("/admin/team/{$owner->id}/role", ['role' => 'learner'])->assertSessionHasErrors('team');
        $this->assertSame('owner', $this->role($tenant, $owner));

        // Inhaber: Admins ernennen und zurückstufen.
        $this->actingAsInTenant($owner, $tenant)->patch("/admin/team/{$instructor->id}/role", ['role' => 'admin'])->assertSessionHasNoErrors();
        $this->assertSame('admin', $this->role($tenant, $instructor));
        $this->actingAsInTenant($owner, $tenant)->patch("/admin/team/{$admin->id}/role", ['role' => 'instructor'])->assertSessionHasNoErrors();
        $this->assertSame('instructor', $this->role($tenant, $admin));
        // „owner“ ist keine vergebbare Rolle.
        $this->actingAsInTenant($owner, $tenant)->patch("/admin/team/{$learner->id}/role", ['role' => 'owner'])->assertSessionHasErrors('role');

        // Ausbilder kommen gar nicht an die Team-Verwaltung.
        $this->actingAsInTenant($admin, $tenant)->patch("/admin/team/{$learner->id}/role", ['role' => 'learner'])->assertForbidden();
    }

    public function test_the_owner_transfers_ownership_to_an_active_admin_only(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $admin = $this->createTenantUser($tenant, 'admin');
        $instructor = $this->createTenantUser($tenant, 'instructor');

        $this->actingAsInTenant($owner, $tenant)->post("/admin/team/{$instructor->id}/transfer-ownership")->assertSessionHasErrors('team');
        $this->actingAsInTenant($admin, $tenant)->post("/admin/team/{$owner->id}/transfer-ownership")->assertForbidden();

        $this->actingAsInTenant($owner, $tenant)->post("/admin/team/{$admin->id}/transfer-ownership")->assertSessionHasNoErrors();
        $this->assertSame('owner', $this->role($tenant, $admin));
        $this->assertSame('admin', $this->role($tenant, $owner));
        $this->assertSame(1, $this->onAdmin(fn () => TenantUser::where('tenant_id', $tenant->id)->where('role', 'owner')->count()));
    }
}
