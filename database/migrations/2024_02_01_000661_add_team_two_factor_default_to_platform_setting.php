<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plattform-Einstellung „2FA-Pflicht für neue Schulen“: Startwert von
 * tenant_branding.require_team_two_factor beim Anlegen einer Schule
 * (Superadmin bzw. Shop-Freigabe). Bestehende Schulen bleiben unverändert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('platform_setting', 'new_tenant_requires_team_two_factor')) {
            Schema::table('platform_setting', fn (Blueprint $table) => $table->boolean('new_tenant_requires_team_two_factor')->default(true));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('platform_setting', 'new_tenant_requires_team_two_factor')) {
            Schema::table('platform_setting', fn (Blueprint $table) => $table->dropColumn('new_tenant_requires_team_two_factor'));
        }
    }
};
