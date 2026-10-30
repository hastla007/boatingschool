<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (RLS-Härtung fehlt bei zwei neueren Tabellen): die Policies
 * radio_training_progress_isolation (000479) und
 * course_exam_registration_isolation (000480) verwendeten noch
 * current_setting(...)::uuid ohne die NULLIF-Härtung aus 000456 -- ein
 * leerer GUC-Wert ließ ''::uuid eine harte Postgres-Exception werfen statt
 * fail-closed keine Zeilen zu liefern. Siehe Migration 000493.
 */
class RlsPolicyHardeningRadioTrainingAndExamRegistrationTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        DB::statement("SET app.current_tenant_id = '00000000-0000-0000-0000-000000000000'");

        parent::tearDown();
    }

    public function test_an_empty_tenant_setting_fails_closed_on_both_tables(): void
    {
        DB::statement("SET app.current_tenant_id = ''");

        $this->assertSame(0, DB::table('radio_training_progress')->count());
        $this->assertSame(0, DB::table('course_exam_registration')->count());
    }

    public function test_no_tenant_policy_casts_the_setting_without_nullif(): void
    {
        $unhardened = $this->onAdmin(fn () => DB::select(<<<'SQL'
            SELECT c.relname AS table_name, p.polname AS policy_name
            FROM pg_policy p
            JOIN pg_class c ON c.oid = p.polrelid
            WHERE regexp_replace(
                    coalesce(pg_get_expr(p.polqual, p.polrelid), '') || ' ' || coalesce(pg_get_expr(p.polwithcheck, p.polrelid), ''),
                    'NULLIF\(current_setting\(''app\.current_tenant_id''::text, true\), ''''::text\)', '', 'g'
                ) LIKE '%current_setting(''app.current_tenant_id''%'
            ORDER BY 1, 2
        SQL));

        $this->assertSame([], array_map(fn ($row) => "{$row->table_name}.{$row->policy_name}", $unhardened));
    }
}
