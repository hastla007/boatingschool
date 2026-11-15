<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile-API: Prüfungsfragen zur späteren Durchsicht markieren. Die Markierung
 * liegt am Server, damit sie beim Fortsetzen auf einem anderen Gerät erhalten
 * bleibt. Sie hat keinen Einfluss auf die Auswertung.
 */
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
