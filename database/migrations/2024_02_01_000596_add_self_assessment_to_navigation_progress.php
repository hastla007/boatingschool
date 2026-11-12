<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Preserve the learner's three-state navigation self-assessment used by the WP mobile API. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('navigation_progress', function (Blueprint $table) {
            $table->string('self_assessment', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('navigation_progress', function (Blueprint $table) {
            $table->dropColumn('self_assessment');
        });
    }
};
