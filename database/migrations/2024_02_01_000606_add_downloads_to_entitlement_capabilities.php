<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE entitlement DROP CONSTRAINT IF EXISTS entitlement_capabilities_values_check');
        DB::statement("ALTER TABLE entitlement ADD CONSTRAINT entitlement_capabilities_values_check CHECK (capabilities IS NULL OR capabilities <@ '[\"smarttrainer\",\"favorites\",\"exam\",\"video\",\"navigation\",\"praxis\",\"funk\",\"downloads\"]'::jsonb)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE entitlement DROP CONSTRAINT IF EXISTS entitlement_capabilities_values_check');
        DB::statement("UPDATE entitlement SET capabilities = capabilities - 'downloads' WHERE capabilities ? 'downloads'");
        DB::statement("ALTER TABLE entitlement ADD CONSTRAINT entitlement_capabilities_values_check CHECK (capabilities IS NULL OR capabilities <@ '[\"smarttrainer\",\"favorites\",\"exam\",\"video\",\"navigation\",\"praxis\",\"funk\"]'::jsonb)");
    }
};
