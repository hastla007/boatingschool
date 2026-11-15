<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile-API: offener Antrag auf Kontolöschung aus der App (bis zur
 * Bestätigung per Mail-Link). Gilt wie die Selbstlöschung im Web für genau
 * eine Bootsschule (tenant_id, RLS); die Bestätigung führt dieselbe
 * Löschung aus (AccountErasureService::leaveTenant), die Zeile verschwindet
 * dabei mit. Bestätigungsschlüssel nur als SHA-256-Hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_account_deletion_request', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->char('confirm_key_hash', 64);
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('expires_at');
            $table->index(['tenant_id', 'user_id']);
            $table->index('user_id');
            $table->index('expires_at');
        });

        DB::statement('ALTER TABLE api_account_deletion_request ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY api_account_deletion_request_isolation ON api_account_deletion_request
            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)");

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON api_account_deletion_request TO '.AppRoleGrants::quotedRole());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_account_deletion_request');
    }
};
