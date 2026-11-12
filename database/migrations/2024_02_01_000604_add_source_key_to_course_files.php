<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_file', function (Blueprint $table) {
            $table->string('source_key', 160)->nullable();
            $table->unique(['course_id', 'source_key']);
        });
    }

    public function down(): void
    {
        Schema::table('course_file', function (Blueprint $table) {
            $table->dropUnique(['course_id', 'source_key']);
            $table->dropColumn('source_key');
        });
    }
};
