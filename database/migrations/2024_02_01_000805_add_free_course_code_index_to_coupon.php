<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lerngruppen-Plätze verbrauchen beim Beitritt je Kurs einen freien
 * Kurscode der Schule (LearningGroupService::join, Coupon::
 * scopeFreeCourseCodesOf). Teilindex nur über offene reine Kurscodes: der
 * Beitritt greift unter Sperre (FOR UPDATE SKIP LOCKED) den nächsten Code je
 * Schule und Kurs, die Admin-Ansicht zählt sie je Kurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_coupon_free_course_codes ON coupon (tenant_id, course_id, expires_at)
            WHERE redeemed_at IS NULL AND voided_at IS NULL AND product_id IS NULL AND is_renewal = false');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_coupon_free_course_codes');
    }
};
