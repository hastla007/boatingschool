<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Audit ARC-3/ARC-4: Indizes für audit_log (Entität, Zeit) und attempt
 * (Zeit), eine gebündelte Pseudonymisierung beim Offboarding statt einer
 * Abfrage je Konto, Aufräumen nach Frist in Blöcken.
 */
class AuditLogIndexAndPruneTest extends TestCase
{
    use InteractsWithTenants;

    public function test_lookup_indexes_exist(): void
    {
        $indexes = collect(DB::connection('pgsql_admin')->select("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename IN ('audit_log', 'attempt')"))
            ->pluck('indexdef', 'indexname');

        $this->assertStringContainsString('ON public.audit_log USING btree (entity_type, entity_id)', $indexes['idx_audit_log_entity'] ?? '');
        $this->assertStringContainsString('ON public.audit_log USING btree (created_at DESC, id DESC)', $indexes['idx_audit_log_time'] ?? '');
        $this->assertStringContainsString('ON public.attempt USING btree (created_at)', $indexes['idx_attempt_created_at'] ?? '');
        $invalid = DB::connection('pgsql_admin')->selectOne("SELECT count(*) AS n FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname IN ('idx_audit_log_entity', 'idx_audit_log_time', 'idx_attempt_created_at') AND NOT i.indisvalid");
        $this->assertSame(0, (int) $invalid->n);
    }

    public function test_offboarding_pseudonymizes_the_audit_log_of_all_orphans_in_one_query(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant('Offboarding Viele');
        $orphans = collect(range(1, 50))->map(fn () => $this->createTenantUser($tenant, 'learner'));
        $this->onAdmin(function () use ($orphans, $tenant) {
            foreach ($orphans as $user) {
                AuditLog::record(null, null, 'superadmin.user.update', 'app_user', $user->id, null, ['email' => $user->email]);
            }
            $tenant->update(['status' => 'closed']);
        });

        $updates = 0;
        DB::listen(function ($query) use (&$updates) {
            if (str_starts_with($query->sql, 'update "audit_log"')) {
                $updates++;
            }
        });
        $this->actingAs($superadmin)->delete("/superadmin/tenants/{$tenant->id}", ['confirm_slug' => $tenant->slug])
            ->assertRedirect(route('superadmin.tenants.index'));

        $this->assertSame(1, $updates);
        $this->onAdmin(function () use ($orphans, $tenant) {
            AuditLog::where('action', 'superadmin.tenant.delete')->where('after_data->slug', $tenant->slug)->delete();
            $this->assertSame(0, User::whereIn('id', $orphans->pluck('id'))->count());
            $this->assertSame(0, AuditLog::where('entity_type', 'app_user')->whereIn('entity_id', $orphans->pluck('id'))->whereNotNull('after_data')->count());
            AuditLog::where('entity_type', 'app_user')->whereIn('entity_id', $orphans->pluck('id'))->delete();
        });
    }

    public function test_expired_audit_log_rows_are_pruned_in_chunks(): void
    {
        config(['data_retention.audit_log.enabled' => true, 'data_retention.audit_log.months' => 24, 'data_retention.prune_chunk' => 5]);
        $tenant = $this->createTestTenant();
        $rows = fn (int $n, $at) => array_map(fn () => [
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'action' => 'arc4.probe', 'entity_type' => 'tenant', 'created_at' => $at,
        ], range(1, $n));
        DB::connection('pgsql_admin')->table('audit_log')->insert(array_merge($rows(12, now()->subYears(3)), $rows(2, now()->subMonth())));
        $old = DB::connection('pgsql_admin')->table('audit_log')->where('created_at', '<', now()->subMonths(24))->count();

        $deletes = 0;
        DB::listen(function ($query) use (&$deletes) {
            if (str_starts_with($query->sql, 'DELETE FROM audit_log WHERE ctid')) {
                $deletes++;
            }
        });
        $this->artisan('app:prune-personal-data')->assertSuccessful()->expectsOutputToContain("audit_log: {$old} Zeile(n) jenseits der Aufbewahrungsfrist gelöscht.");

        $this->assertSame(intdiv($old, 5) + 1, $deletes, 'Blöcke à 5 Zeilen');
        $this->assertSame(0, DB::connection('pgsql_admin')->table('audit_log')->where('created_at', '<', now()->subMonths(24))->count());
        $this->assertSame(2, DB::connection('pgsql_admin')->table('audit_log')->where('tenant_id', $tenant->id)->count());
    }
}
