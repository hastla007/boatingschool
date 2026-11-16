<?php

namespace Tests\Feature;

use App\Api\DeviceSessions;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\AccountErasureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Vollständige Kontolöschung (AccountErasureService::deleteAccount, z. B.
 * durch app:prune-inactive-learners): Lerngruppen-Plätze und -Einladungen,
 * App-Einstellungen, Einmal-Codes der Webansicht und offene Löschanträge
 * verschwinden in jeder Schule; verwaltete Gruppen bleiben ohne Verwalter,
 * der Verwalter verschwindet aus deren Protokolldaten.
 */
class AccountErasureCoversLearningGroupsAndAppTablesTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function group(Tenant $tenant, ?User $owner): string
    {
        $id = (string) Str::uuid();
        DB::table('learning_group')->insert([
            'id' => $id, 'tenant_id' => $tenant->id, 'owner_user_id' => $owner?->id, 'name' => 'Gruppe', 'seats' => 5,
            'scopes' => json_encode([['course_code' => 'SBF-SEE', 'feature' => 'course']]),
            'join_code' => 'g'.bin2hex(random_bytes(16)), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_delete_account_removes_the_rows_of_the_new_tables_in_every_school(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $tenantC = $this->createTestTenant();
        $user = $this->createTenantUser($tenantA, 'learner', ['email' => 'loeschen-'.Str::random(6).'@schule.test']);
        $other = $this->createTenantUser($tenantA, 'learner');

        $managedGroup = $this->onAdmin(function () use ($tenantA, $tenantB, $tenantC, $user, $other) {
            TenantUser::create(['tenant_id' => $tenantB->id, 'user_id' => $user->id, 'role' => 'learner', 'status' => 'active']);

            $managed = $this->group($tenantA, $user);
            DB::table('learning_group_member')->insert([
                'tenant_id' => $tenantA->id, 'group_id' => $managed, 'user_id' => $other->id, 'status' => 'active', 'joined_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            AuditLog::record($tenantA->id, null, 'learning_group.create', 'learning_group', $managed, null, ['name' => 'Gruppe', 'owner_user_id' => $user->id]);
            // Platz in Schule B, offene Einladung an die Adresse in Schule C (ohne Mitgliedschaft dort).
            DB::table('learning_group_member')->insert([
                ['tenant_id' => $tenantB->id, 'group_id' => $this->group($tenantB, null), 'user_id' => $user->id, 'email' => null, 'status' => 'active',
                    'invite_token' => null, 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['tenant_id' => $tenantC->id, 'group_id' => $this->group($tenantC, null), 'user_id' => null, 'email' => strtoupper($user->email), 'status' => 'invited',
                    'invite_token' => 'i'.bin2hex(random_bytes(16)), 'joined_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ]);

            $device = app(DeviceSessions::class)->issue($user, 'Testgerät', 'ios', '1.0')['session'];
            foreach ([$tenantA, $tenantB] as $tenant) {
                DB::table('api_mobile_web_session')->insert([
                    'tenant_id' => $tenant->id, 'user_id' => $user->id, 'api_device_session_id' => $device->id,
                    'code_hash' => hash('sha256', Str::random()), 'verifier_hash' => hash('sha256', 'v'), 'redirect_path' => '/dashboard',
                    'expires_at' => now()->addMinute(),
                ]);
                DB::table('api_account_deletion_request')->insert([
                    'tenant_id' => $tenant->id, 'user_id' => $user->id, 'confirm_key_hash' => hash('sha256', Str::random()), 'expires_at' => now()->addDays(7),
                ]);
            }
            DB::table('api_user_setting')->insert(['user_id' => $user->id, 'settings' => json_encode(['theme' => 'dark'])]);

            return $managed;
        });

        $this->onAdmin(fn () => app(AccountErasureService::class)->deleteAccount(User::findOrFail($user->id)));

        $this->onAdmin(function () use ($user, $managedGroup, $other) {
            $this->assertNull(User::find($user->id));
            $this->assertFalse(DB::table('learning_group_member')->where('user_id', $user->id)->exists());
            $this->assertFalse(DB::table('learning_group_member')->whereRaw('lower(email) = ?', [mb_strtolower($user->email)])->exists());
            foreach (['api_mobile_web_session', 'api_account_deletion_request', 'api_user_setting', 'api_device_session'] as $table) {
                $this->assertFalse(DB::table($table)->where('user_id', $user->id)->exists(), $table);
            }
            // Verwaltete Gruppe bleibt (die Mitglieder behalten ihren Platz), ohne Verwalter.
            $this->assertNull(DB::table('learning_group')->where('id', $managedGroup)->value('owner_user_id'));
            $this->assertTrue(DB::table('learning_group_member')->where('group_id', $managedGroup)->where('user_id', $other->id)->exists());
            $entry = AuditLog::where('entity_type', 'learning_group')->where('entity_id', $managedGroup)->sole();
            $this->assertNull($entry->after_data);
        });
    }
}
