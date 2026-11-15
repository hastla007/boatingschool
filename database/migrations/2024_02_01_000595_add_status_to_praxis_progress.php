<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Praxistrainer der App: drei Stände je Karte (open/practice/known) neben
 * dem Web-Flag "completed". known <=> completed; Web und App halten beides synchron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('praxis_progress', function (Blueprint $table) {
            $table->string('status', 16)->default('open');
        });
        DB::statement("ALTER TABLE praxis_progress ADD CONSTRAINT praxis_progress_status_check CHECK (status IN ('open', 'practice', 'known'))");
        DB::table('praxis_progress')->where('completed', true)->update(['status' => 'known']);
    }

    public function down(): void
    {
        Schema::table('praxis_progress', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
