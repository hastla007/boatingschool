<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sicherheitsreview H1 (Coupon-Doppeleinlösung): die eigentliche Sperre
 * sitzt jetzt in CouponRedemptionService::redeem() (SELECT ... FOR UPDATE
 * auf die Coupon-Zeile) und Admin\CouponController::import() (atomares
 * UPDATE ... WHERE tenant_id IS NULL) -- diese beiden Unique-Indizes sind
 * zusätzliche Verteidigung auf Datenbankebene, falls ein künftiger
 * Code-Pfad die Sperre umgeht: pro Coupon-Code darf höchstens ein
 * Entitlement bzw. (je gebündeltem Produkt) ein ProductPurchase entstehen.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX entitlement_coupon_source_unique ON entitlement (source_type, source_reference)
            WHERE source_type IN ('coupon', 'coupon_renewal') AND source_reference IS NOT NULL");

        DB::statement("CREATE UNIQUE INDEX product_purchase_coupon_source_unique ON product_purchase (source_type, source_reference, product_id)
            WHERE source_type = 'coupon' AND source_reference IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS entitlement_coupon_source_unique');
        DB::statement('DROP INDEX IF EXISTS product_purchase_coupon_source_unique');
    }
};
