<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit SEC-W-3: der beim Einladen angegebene Name. Offene Einladungen
 * erscheinen damit in Teilnehmer- und Teamliste wie neu angelegte, noch
 * nicht angemeldete Konten -- die Liste verrät nicht mehr ohne Weiteres,
 * ob es zur Adresse schon ein Konto auf der Plattform gibt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_invitation', 'name')) {
            Schema::table('tenant_invitation', fn (Blueprint $table) => $table->string('name')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_invitation', 'name')) {
            Schema::table('tenant_invitation', fn (Blueprint $table) => $table->dropColumn('name'));
        }
    }
};
