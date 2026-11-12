<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Persist learner review flags on running exam questions for cross-device resume. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_session_question', function (Blueprint $table) {
            $table->boolean('flagged')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('exam_session_question', function (Blueprint $table) {
            $table->dropColumn('flagged');
        });
    }
};
