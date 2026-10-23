<?php

namespace Tests\Feature;

use App\Models\TenantUser;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview M1: ein gesperrtes Konto (app_user.status) oder eine
 * gesperrte Mitgliedschaft (tenant_user.status) behielt bisher vollen
 * Zugang -- weder der Login noch roleForTenant() prüften den Status, und
 * Bootsschulen hatten keine Möglichkeit, einen Teilnehmer zu entfernen.
 */
class AccountSuspensionTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_a_suspended_account_cannot_log_in(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['status' => 'suspended']);

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_an_active_account_can_still_log_in(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    /**
     * Bug-Review B7: Laravels Remember-me-Mechanismus meldet ein Konto beim
     * nächsten Request automatisch wieder an, sobald Token und Nutzer-ID
     * zusammenpassen (EloquentUserProvider::retrieveByToken()) -- dabei
     * wird NIE geprüft, ob das Konto überhaupt noch status=active hat.
     * Eine bereits laufende (Remember-me- oder reguläre) Sitzung überlebte
     * eine nachträgliche Sperrung deshalb bislang bis zum nächsten echten
     * Login-Versuch. EnsureAccountIsActive prüft den Status jetzt bei
     * JEDEM Request neu und meldet ein zwischenzeitlich gesperrtes Konto
     * sofort ab -- unabhängig davon, ob es per Session oder per
     * Remember-me-Cookie "angemeldet" ist.
     */
    public function test_a_remember_me_cookie_does_not_keep_a_newly_suspended_account_logged_in(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $login = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1']);
        $login->assertRedirect();
        $this->assertAuthenticatedAs($user);

        // Direkte DB-Änderung statt Superadmin-Endpunkt: isoliert diesen
        // Test bewusst von der bereits separat geprüften Token-Rotation
        // (siehe test_superadmin_suspending_a_user_also_invalidates_...)
        // und prüft wirklich nur die hier neue, davon unabhängige Prüfung.
        $this->onAdmin(fn () => $user->forceFill(['status' => 'suspended'])->save());

        // In echten Requests ist jeder Request ein neuer Prozess, der
        // $user frisch aus der Session-ID nachlädt -- im Testprozess bleibt
        // der SessionGuard dagegen über beide simulierten Requests hinweg
        // derselbe und cached den (hier veralteten) $user aus dem Login.
        // forgetGuards() erzwingt die in echten Requests selbstverständliche
        // frische Auflösung, ohne den eigentlichen Prüfpfad zu verändern.
        $this->app['auth']->forgetGuards();

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/dashboard');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_suspended_membership_blocks_access_to_that_tenant(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $this->onAdmin(fn () => TenantUser::where('tenant_id', $tenant->id)->where('user_id', $learner->id)
            ->update(['status' => 'suspended']));

        $response = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');

        $response->assertForbidden();
    }

    public function test_a_school_admin_can_suspend_and_reactivate_a_participant(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');

        $suspend = $this->actingAsInTenant($admin, $tenant)->patch("/admin/participants/{$learner->id}/membership", [
            'status' => 'suspended',
        ]);
        $suspend->assertRedirect();
        $this->assertSame('suspended', $this->asTenant($tenant, fn () => TenantUser::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->value('status')));

        $blocked = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');
        $blocked->assertForbidden();

        $reactivate = $this->actingAsInTenant($admin, $tenant)->patch("/admin/participants/{$learner->id}/membership", [
            'status' => 'active',
        ]);
        $reactivate->assertRedirect();
        $this->assertSame('active', $this->asTenant($tenant, fn () => TenantUser::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->value('status')));

        $allowed = $this->actingAsInTenant($learner, $tenant)->get('/dashboard');
        $allowed->assertOk();
    }

    public function test_an_instructor_cannot_suspend_a_participant(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($instructor, $tenant)->patch("/admin/participants/{$learner->id}/membership", [
            'status' => 'suspended',
        ]);

        $response->assertForbidden();
        $this->assertSame('active', $this->asTenant($tenant, fn () => TenantUser::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->value('status')));
    }

    public function test_an_admin_cannot_suspend_their_own_membership(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->patch("/admin/participants/{$admin->id}/membership", [
            'status' => 'suspended',
        ]);

        $response->assertStatus(422);
        $this->assertSame('active', $this->asTenant($tenant, fn () => TenantUser::where('tenant_id', $tenant->id)->where('user_id', $admin->id)->value('status')));
    }

    /**
     * Bug-Review N11: diese Route liegt hinter tenant.role:owner,admin --
     * ohne eine zusätzliche Rollenprüfung konnte ein Admin damit die
     * Mitgliedschaft des Owners (oder eines anderen Admins) sperren.
     */
    public function test_an_admin_cannot_suspend_the_owners_membership(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($admin, $tenant)->patch("/admin/participants/{$owner->id}/membership", [
            'status' => 'suspended',
        ]);

        $response->assertNotFound();
        $this->assertSame('active', $this->asTenant($tenant, fn () => TenantUser::where('tenant_id', $tenant->id)->where('user_id', $owner->id)->value('status')));
    }

    public function test_an_admin_cannot_suspend_another_admins_membership(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $otherAdmin = $this->createTenantUser($tenant, 'admin');

        $response = $this->actingAsInTenant($admin, $tenant)->patch("/admin/participants/{$otherAdmin->id}/membership", [
            'status' => 'suspended',
        ]);

        $response->assertNotFound();
        $this->assertSame('active', $this->asTenant($tenant, fn () => TenantUser::where('tenant_id', $tenant->id)->where('user_id', $otherAdmin->id)->value('status')));
    }

    public function test_superadmin_suspending_a_user_ends_their_sessions(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $this->actingAsInTenant($user, $tenant)->get('/dashboard')->assertOk();
        $this->onAdmin(fn () => \Illuminate\Support\Facades\DB::table('sessions')->insert([
            'id' => 'fake-session-for-test',
            'user_id' => $user->id,
            'payload' => base64_encode('test'),
            'last_activity' => now()->timestamp,
        ]));

        $response = $this->actingAs($superadmin)->patch("/superadmin/users/{$user->id}", [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
            'status' => 'suspended',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    /**
     * Sicherheitsreview M1/N6: das Löschen der Sessions allein reicht nicht
     * -- ein "Angemeldet bleiben"-Cookie meldet den gesperrten Nutzer beim
     * nächsten Request sonst automatisch wieder an, ganz ohne Passwort,
     * weil Laravel dafür nur den (unveränderten) remember_token prüft.
     */
    public function test_superadmin_suspending_a_user_also_invalidates_their_remember_me_cookie(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['remember_token' => 'original-remember-token']);

        $response = $this->actingAs($superadmin)->patch("/superadmin/users/{$user->id}", [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
            'status' => 'suspended',
        ]);

        $response->assertRedirect();
        $this->assertNotSame('original-remember-token', $user->fresh()->remember_token);
    }

    public function test_suspending_an_already_inactive_user_does_not_touch_their_remember_me_cookie_again(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner', ['status' => 'suspended', 'remember_token' => 'still-the-same-token']);

        $response = $this->actingAs($superadmin)->patch("/superadmin/users/{$user->id}", [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
            'status' => 'closed',
        ]);

        $response->assertRedirect();
        $this->assertSame('still-the-same-token', $user->fresh()->remember_token);
    }
}
