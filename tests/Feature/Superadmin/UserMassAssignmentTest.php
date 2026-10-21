<?php

namespace Tests\Feature\Superadmin;

use App\Models\User;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview N4: is_superadmin stand bislang in $fillable -- aktuell
 * nirgends ausnutzbar (alle Aufrufe nutzen explizite Felder/validated()),
 * aber eine Mass-Assignment-Falle für künftigen Code. Entfernt aus
 * $fillable; der Superadmin-Controller setzt das Feld jetzt bewusst per
 * forceFill.
 */
class UserMassAssignmentTest extends TestCase
{
    use InteractsWithTenants;

    public function test_is_superadmin_is_not_mass_assignable_on_the_model(): void
    {
        $user = $this->onAdmin(fn () => User::create([
            'email' => 'masstest-'.uniqid().'@example.test',
            'display_name' => 'Mass Assignment Test',
            'password' => bcrypt('password'),
            'status' => 'active',
            'is_superadmin' => true,
        ]));
        $this->createdUserIds[] = $user->id;

        $this->assertFalse($user->fresh()->is_superadmin);
    }

    public function test_superadmin_can_still_grant_superadmin_rights_via_the_user_edit_form(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $target = $this->createTenantUser($tenant, 'admin');

        $response = $this->actingAs($superadmin)->patch("/superadmin/users/{$target->id}", [
            'first_name' => 'Target',
            'last_name' => 'User',
            'email' => $target->email,
            'status' => 'active',
            'is_superadmin' => '1',
        ]);

        $response->assertRedirect();
        $this->assertTrue($target->fresh()->is_superadmin);
    }

    public function test_superadmin_can_still_revoke_superadmin_rights(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $target = $this->createTenantUser($tenant, 'admin', ['is_superadmin' => true]);

        $response = $this->actingAs($superadmin)->patch("/superadmin/users/{$target->id}", [
            'first_name' => 'Target',
            'last_name' => 'User',
            'email' => $target->email,
            'status' => 'active',
        ]);

        $response->assertRedirect();
        $this->assertFalse($target->fresh()->is_superadmin);
    }
}
