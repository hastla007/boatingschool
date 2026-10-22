<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * DSGVO-Review D3: Aufbewahrungs-/Löschfristen für abgelaufene Sessions
 * sowie (falls per .env aktiviert) audit_log und attempt.
 */
class PrunePersonalDataTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdSessionIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdSessionIds as $id) {
                DB::table('sessions')->where('id', $id)->delete();
            }
        });

        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    private function insertSession(string $userId, int $ageInDays): string
    {
        $id = (string) Str::uuid();
        $this->createdSessionIds[] = $id;

        $this->onAdmin(fn () => DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'payload' => base64_encode('test'),
            'last_activity' => now()->subDays($ageInDays)->timestamp,
        ]));

        return $id;
    }

    public function test_expired_sessions_are_pruned(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $expired = $this->insertSession($user->id, 31);
        $recent = $this->insertSession($user->id, 1);

        $this->artisan('app:prune-personal-data')->assertExitCode(0);

        $this->assertDatabaseMissing('sessions', ['id' => $expired]);
        $this->assertDatabaseHas('sessions', ['id' => $recent]);
    }

    public function test_audit_log_pruning_is_disabled_by_default(): void
    {
        $this->assertFalse(config('data_retention.audit_log.enabled'));
        $this->assertFalse(config('data_retention.attempt.enabled'));
    }

    public function test_dry_run_does_not_delete_anything(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');

        $id = $this->insertSession($user->id, 60);

        $this->artisan('app:prune-personal-data --dry-run')->assertExitCode(0);

        $this->assertDatabaseHas('sessions', ['id' => $id]);
    }
}
