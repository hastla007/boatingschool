<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Herkunft importierter WP-Zugänge (app:import-mobile-entitlements, Format 2):
 * source (woocommerce/group/manual/voucher), group_id, order_id und der
 * WP-Status. source_reference bleibt der Quellschlüssel (wp:entitlement:<id>),
 * über den der Import idempotent ist; deshalb eine eigene, optionale Spalte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE entitlement ADD COLUMN import_meta jsonb NULL');
        DB::statement("ALTER TABLE entitlement ADD CONSTRAINT entitlement_import_meta_object_check CHECK (import_meta IS NULL OR jsonb_typeof(import_meta) = 'object')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE entitlement DROP CONSTRAINT IF EXISTS entitlement_import_meta_object_check');
        DB::statement('ALTER TABLE entitlement DROP COLUMN IF EXISTS import_meta');
    }
};
