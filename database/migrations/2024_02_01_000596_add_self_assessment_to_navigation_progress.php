<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Navigationstrainer: Selbstbewertung je Teilaufgabe (richtig/teilweise/falsch) wie in der bisherigen App. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('navigation_progress', function (Blueprint $table) {
            $table->string('self_assessment', 8)->nullable();
        });
        DB::statement("ALTER TABLE navigation_progress ADD CONSTRAINT navigation_progress_self_assessment_check CHECK (self_assessment IS NULL OR self_assessment IN ('correct', 'partly', 'wrong'))");
    }

    public function down(): void
    {
        Schema::table('navigation_progress', function (Blueprint $table) {
            $table->dropColumn('self_assessment');
        });
    }
};
