<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * coupon_batch auch für Code-Pakete des Superadmins (CouponBatchFactory,
 * Quelle „superadmin“): ohne Shop-Angaben und ggf. ohne Schule (Codes
 * frei einlösbar). Für Shop-Lieferungen bleiben Schule, Shop, Bestellung,
 * Position und Fingerabdruck Pflicht (Check-Constraint).
 */
return new class extends Migration
{
    private const COLUMNS = ['tenant_id', 'shop_id', 'order_id', 'order_line_id', 'request_fingerprint'];

    public function up(): void
    {
        foreach (self::COLUMNS as $column) {
            DB::statement("ALTER TABLE coupon_batch ALTER COLUMN {$column} DROP NOT NULL");
        }
        DB::statement("ALTER TABLE coupon_batch ADD CONSTRAINT coupon_batch_source_check CHECK (source IN ('woocommerce', 'superadmin'))");
        DB::statement("ALTER TABLE coupon_batch ADD CONSTRAINT coupon_batch_shop_fields_check CHECK (
            source <> 'woocommerce' OR (tenant_id IS NOT NULL AND shop_id IS NOT NULL AND order_id IS NOT NULL
                AND order_line_id IS NOT NULL AND request_fingerprint IS NOT NULL))");
        DB::statement('CREATE INDEX coupon_batch_source_index ON coupon_batch (source)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS coupon_batch_source_index');
        DB::statement('ALTER TABLE coupon_batch DROP CONSTRAINT IF EXISTS coupon_batch_shop_fields_check');
        DB::statement('ALTER TABLE coupon_batch DROP CONSTRAINT IF EXISTS coupon_batch_source_check');
        // Pakete ohne Shop-Angaben passen nicht mehr ins alte Schema (die Codes bleiben, batch_id wird null).
        DB::table('coupon_batch')->where('source', '<>', 'woocommerce')->delete();
        foreach (self::COLUMNS as $column) {
            DB::statement("ALTER TABLE coupon_batch ALTER COLUMN {$column} SET NOT NULL");
        }
    }
};
