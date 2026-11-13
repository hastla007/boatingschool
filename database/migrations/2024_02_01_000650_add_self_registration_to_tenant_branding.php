<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schalter je Bootsschule „Selbstregistrierung erlauben“: ist er aus,
 * entstehen Teilnehmerkonten nur noch über eine Einladung der Schule
 * (Registrierungsseite zeigt einen Hinweis, das Absenden wird abgelehnt).
 * Standard an, damit sich für bestehende Schulen nichts ändert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_branding', 'self_registration_enabled')) {
            Schema::table('tenant_branding', fn (Blueprint $table) => $table->boolean('self_registration_enabled')->default(true));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_branding', 'self_registration_enabled')) {
            Schema::table('tenant_branding', fn (Blueprint $table) => $table->dropColumn('self_registration_enabled'));
        }
    }
};
