<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile-API: geräteübergreifend synchronisierte App-Einstellungen je Konto
 * (GET/PUT /api/v1/me/settings, optimistische Versionierung).
 *
 * Schulübergreifend (ein Konto, mehrere Schulen) und deshalb wie
 * api_device_session ohne tenant_id und ohne RLS; Zugriff nur über die
 * eigene user_id. Personenbezogen: Teil von Auskunft (PersonalDataExportService)
 * und Kontolöschung (AccountErasureService, zusätzlich ON DELETE CASCADE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_user_setting', function (Blueprint $table) {
            $table->uuid('user_id')->primary();
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->jsonb('settings')->default(DB::raw("'{}'::jsonb"));
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON api_user_setting TO '.AppRoleGrants::quotedRole());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_user_setting');
    }
};
