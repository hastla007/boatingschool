<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prüfungsregeln (Zeitlimit, Bestehensgrenze, Prüfungsteile) beim Start
 * einer Prüfung festhalten: ändert der Superadmin das Regelwerk danach,
 * gelten für laufende und schon bewertete Prüfungen weiter die Regeln,
 * unter denen sie abgelegt wurden. Bestehende Sessions behalten null und
 * nutzen weiter das aktuelle Regelwerk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_session', function (Blueprint $table) {
            $table->jsonb('rule_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exam_session', function (Blueprint $table) {
            $table->dropColumn('rule_snapshot');
        });
    }
};
