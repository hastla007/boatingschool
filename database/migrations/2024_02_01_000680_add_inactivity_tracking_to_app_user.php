<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Löschung inaktiver Lernender (app:prune-inactive-learners, nur wenn
 * DATA_RETENTION_INACTIVE_LEARNER_MONTHS gesetzt ist):
 *
 * - last_login_at: letzte Anmeldung (Web oder App). Bisher gab es dafür
 *   keine dauerhafte Spalte -- Sitzungen verschwinden nach 30 Tagen
 *   (data_retention.sessions), daraus ließe sich eine Inaktivität über
 *   Monate nicht ablesen.
 * - inactivity_warned_at: wann die Vorwarnung („dein Konto wird in 30 Tagen
 *   gelöscht“) verschickt wurde; gelöscht wird frühestens 30 Tage danach.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_user', function (Blueprint $table) {
            if (! Schema::hasColumn('app_user', 'last_login_at')) {
                $table->timestampTz('last_login_at')->nullable();
            }
            if (! Schema::hasColumn('app_user', 'inactivity_warned_at')) {
                $table->timestampTz('inactivity_warned_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('app_user', fn (Blueprint $table) => $table->dropColumn(['last_login_at', 'inactivity_warned_at']));
    }
};
