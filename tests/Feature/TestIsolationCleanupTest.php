<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review Testisolation: audit_log.tenant_id ist bewusst nullOnDelete()
 * (ein echtes Audit-Log soll eine gelöschte Bootsschule überleben) und
 * password_reset_tokens hat gar keinen Fremdschlüssel (nur per E-Mail-
 * String geschlüsselt) -- beide Tabellen wurden beim bisherigen
 * cleanUpCreatedTenants()/cleanUpCreatedUsers() deshalb nie mitgeräumt und
 * sammelten über jeden Testlauf hinweg dauerhafte, verwaiste Zeilen an.
 */
class TestIsolationCleanupTest extends TestCase
{
    use InteractsWithTenants;

    public function test_cleaning_up_a_tenant_also_removes_its_audit_log_entries(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'learner');
        $marker = 'test.isolation.'.uniqid();

        $this->onAdmin(function () use ($tenant, $admin, $marker) {
            AuditLog::record($tenant->id, $admin->id, $marker, 'test_entity', null);
            $this->assertDatabaseHas('audit_log', ['action' => $marker]);
        });

        $this->cleanUpCreatedTenants();

        // Bewusst nach der eindeutigen "action" gesucht, nicht nach
        // tenant_id: audit_log.tenant_id ist nullOnDelete() -- ein Test, der
        // nur noch auf tenant_id prüft, würde selbst dann grün bleiben, wenn
        // die Zeile nur verwaist (tenant_id = NULL) statt wirklich gelöscht
        // worden wäre.
        $this->onAdmin(function () use ($marker) {
            $this->assertDatabaseMissing('audit_log', ['action' => $marker]);
        });
    }

    public function test_cleaning_up_a_user_also_removes_their_password_reset_token(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $this->onAdmin(function () use ($learner) {
            DB::table('password_reset_tokens')->insert([
                'email' => $learner->email,
                'token' => 'test-token',
                'created_at' => now(),
            ]);
            $this->assertDatabaseHas('password_reset_tokens', ['email' => $learner->email]);
        });

        $this->cleanUpCreatedUsers();

        $this->onAdmin(function () use ($learner) {
            $this->assertDatabaseMissing('password_reset_tokens', ['email' => $learner->email]);
        });
    }
}
