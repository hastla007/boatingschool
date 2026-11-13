<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zeitpunkt der letzten Token-Erneuerung: eine Wiederholung derselben
 * Erneuerung kurz danach (Antwort im Funkloch verloren, zwei parallele
 * Erneuerungen) beendet die Sitzung nicht mehr.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_device_session', function (Blueprint $table) {
            $table->timestampTz('refreshed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('api_device_session', function (Blueprint $table) {
            $table->dropColumn('refreshed_at');
        });
    }
};
