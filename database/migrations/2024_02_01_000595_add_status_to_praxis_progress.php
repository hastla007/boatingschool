<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Preserve the three mobile practice-card states alongside the web completion flag. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('praxis_progress', function (Blueprint $table) {
            $table->string('status', 16)->default('open');
        });
        DB::table('praxis_progress')->where('completed', true)->update(['status' => 'known']);
    }

    public function down(): void
    {
        Schema::table('praxis_progress', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
