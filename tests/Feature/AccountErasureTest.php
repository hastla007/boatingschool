<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ContentQuestionRevision;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * DSGVO-Review D1/D2: eine Kontolöschung wirkte bislang global -- sie löschte
 * das Konto samt aller Mitgliedschaften und Lerndaten bei jeder Bootsschule,
 * ohne Rücksicht auf die Mitgliedschaft bei anderen (eigenständig
 * verantwortlichen) Bootsschulen. Außerdem blieben Sessions, Reset-Tokens
 * und personenbezogene Daten im Audit-Log-JSON stehen, und drei
 * Fremdschlüssel ohne ON DELETE ließen die Löschung mit einem 500er
 * abbrechen.
 */
class AccountErasureTest extends TestCase
{
    use InteractsWithTenants;

    public function test_deleting_the_account_at_one_tenant_keeps_the_membership_at_another(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $user = $this->createTenantUser($tenantA, 'learner');
        $this->onAdmin(fn () => TenantUser::create([
            'tenant_id' => $tenantB->id,
            'user_id' => $user->id,
            'role' => 'learner',
            'status' => 'active',
        ]));

        $response = $this->actingAsInTenant($user, $tenantA)->delete('/profile', [
            'password' => 'password',
        ]);

        $response->assertSessionHasNoErrors();

        // Konto existiert weiter -- nur die Mitgliedschaft bei Schule A ist weg.
        // assertDatabaseHas/-Missing laufen über die reguläre, RLS-
        // beschränkte Verbindung -- deren Tenant-Kontext steht nach dem
        // letzten Request noch auf Schule A, daher über onAdmin() prüfen.
        $this->assertNotNull($user->fresh());
        $this->onAdmin(function () use ($tenantA, $tenantB, $user) {
            $this->assertDatabaseMissing('tenant_user', ['tenant_id' => $tenantA->id, 'user_id' => $user->id]);
            $this->assertDatabaseHas('tenant_user', ['tenant_id' => $tenantB->id, 'user_id' => $user->id]);
        });

        $this->createdUserIds[] = $user->id;
    }

    public function test_deleting_the_last_membership_deletes_the_account_entirely(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($user, $tenant)->delete('/profile', [
            'password' => 'password',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertNull($user->fresh());
    }

    /**
     * Bug-Review (AA7: Inhaber löscht sein Konto -- Schule ohne Inhaber):
     * der einzige Inhaber darf sein Konto nicht löschen -- die Schule stünde
     * danach ohne Inhaber da, und Admin\ParticipantController::update()/
     * updateMembership() verlangen für jede Nicht-Lerner-Mitgliedschaft
     * ausdrücklich role === 'owner', sodass sich keine Rolle mehr ändern
     * ließe (nur noch der Superadmin könnte eingreifen).
     */
    public function test_the_sole_owner_cannot_delete_their_account(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->delete('/profile', [
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('owner', null, 'userDeletion');
        $this->assertNotNull($owner->fresh());
        $this->onAdmin(fn () => $this->assertDatabaseHas('tenant_user', ['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'role' => 'owner']));

        $this->createdUserIds[] = $owner->id;
    }

    /**
     * Gegenprobe zum Test oben: mit einem zweiten aktiven Inhaber bleibt die
     * Schule nach der Löschung weiterhin führungsfähig -- die Sperre greift
     * deshalb nicht.
     */
    public function test_an_owner_can_delete_their_account_if_another_active_owner_remains(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $coOwner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->delete('/profile', [
            'password' => 'password',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertNull($owner->fresh());

        $this->createdUserIds[] = $coOwner->id;
    }

    public function test_leaving_a_tenant_removes_that_tenants_learning_data_but_not_a_second_tenants(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $user = $this->createTenantUser($tenantA, 'learner');
        $this->onAdmin(fn () => TenantUser::create([
            'tenant_id' => $tenantB->id,
            'user_id' => $user->id,
            'role' => 'learner',
            'status' => 'active',
        ]));

        $courseA = $this->existingCourse('SRC');
        $courseB = $this->existingCourse('UBI');
        $this->grantEntitlement($tenantA, $user, $courseA);
        $this->grantEntitlement($tenantB, $user, $courseB);

        $this->actingAsInTenant($user, $tenantA)->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->onAdmin(function () use ($tenantA, $tenantB, $user) {
            $this->assertDatabaseMissing('entitlement', ['tenant_id' => $tenantA->id, 'user_id' => $user->id]);
            $this->assertDatabaseHas('entitlement', ['tenant_id' => $tenantB->id, 'user_id' => $user->id]);
        });

        $this->createdUserIds[] = $user->id;
    }

    public function test_personal_data_is_removed_from_audit_log_entries_about_the_deleted_user(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $user = $this->createTenantUser($tenant, 'learner');

        $this->onAdmin(fn () => AuditLog::create([
            'tenant_id' => $tenant->id,
            'actor_user_id' => $admin->id,
            'action' => 'participant.invite',
            'entity_type' => 'app_user',
            'entity_id' => $user->id,
            'after_data' => ['email' => $user->email],
        ]));

        $this->actingAsInTenant($user, $tenant)->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->onAdmin(function () use ($user) {
            $entry = DB::table('audit_log')->where('entity_type', 'app_user')->where('entity_id', $user->id)->first();
            $this->assertNotNull($entry);
            $this->assertNull($entry->after_data);
        });
    }

    public function test_sessions_and_password_reset_tokens_are_removed_when_the_account_is_fully_deleted(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $this->onAdmin(function () use ($user) {
            DB::table('sessions')->insert([
                'id' => 'erasure-test-session',
                'user_id' => $user->id,
                'payload' => base64_encode('test'),
                'last_activity' => now()->timestamp,
            ]);
            DB::table('password_reset_tokens')->insert([
                'email' => $user->email,
                'token' => 'dummy-token',
                'created_at' => now(),
            ]);
        });

        $this->actingAsInTenant($user, $tenant)->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'erasure-test-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    /**
     * DSGVO-Review D2 (Folgefehler): eine Einladung an eine bereits
     * existierende Adresse legt nur eine tenant_invitation-Zeile an (noch
     * keine Mitgliedschaft, siehe Sicherheitsreview H2) -- die stand
     * bisher nirgends in dieser Löschung und überlebte sie deshalb
     * unverändert mit der eingeladenen E-Mail-Adresse.
     */
    public function test_leaving_a_tenant_also_removes_a_pending_invitation_for_the_users_email(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $user = $this->createTenantUser($tenant, 'learner');

        $invitation = $this->onAdmin(fn () => TenantInvitation::create([
            'tenant_id' => $tenant->id,
            'email' => $user->email,
            'role' => 'learner',
            'invited_by_user_id' => $admin->id,
        ]));

        $this->actingAsInTenant($user, $tenant)->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->onAdmin(function () use ($invitation) {
            $this->assertDatabaseMissing('tenant_invitation', ['id' => $invitation->id]);
        });

        $this->createdUserIds[] = $admin->id;
    }

    /**
     * DSGVO-Review D2 (Folgefehler): participant.invite_pending
     * protokolliert mit entity_type=tenant_invitation und
     * entity_id=<Einladungs-ID>, nicht mit der User-ID -- die bisherige
     * Pseudonymisierung (actor_user_id oder entity_type=app_user) erfasste
     * diese Zeilen nie, die E-Mail-Adresse im JSON überlebte deshalb auch
     * jede Löschung.
     */
    public function test_leaving_a_tenant_also_removes_the_email_from_the_invitation_audit_log_entry(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $user = $this->createTenantUser($tenant, 'learner');

        $invitation = $this->onAdmin(fn () => TenantInvitation::create([
            'tenant_id' => $tenant->id,
            'email' => $user->email,
            'role' => 'learner',
            'invited_by_user_id' => $admin->id,
        ]));

        $this->onAdmin(fn () => AuditLog::create([
            'tenant_id' => $tenant->id,
            'actor_user_id' => $admin->id,
            'action' => 'participant.invite_pending',
            'entity_type' => 'tenant_invitation',
            'entity_id' => $invitation->id,
            'after_data' => ['email' => $user->email],
        ]));

        $this->actingAsInTenant($user, $tenant)->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->onAdmin(function () use ($invitation) {
            $entry = DB::table('audit_log')->where('entity_type', 'tenant_invitation')->where('entity_id', $invitation->id)->first();
            $this->assertNotNull($entry);
            $this->assertNull($entry->after_data);
        });

        $this->createdUserIds[] = $admin->id;
    }

    public function test_deleting_a_user_who_reviewed_a_question_revision_does_not_fail(): void
    {
        $tenant = $this->createTestTenant();
        $reviewer = $this->createTenantUser($tenant, 'learner');

        $this->onAdmin(function () use ($reviewer) {
            $revision = ContentQuestionRevision::whereNotNull('id')->first();
            $revision->update(['reviewed_by' => $reviewer->id]);
        });

        $this->actingAsInTenant($reviewer, $tenant)->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertNull($reviewer->fresh());
    }
}
