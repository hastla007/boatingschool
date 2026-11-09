<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile-API v1: Gerätesitzungen (Access-/Refresh-Token je Gerät, nur als
 * SHA-256-Hash gespeichert), Idempotenz-Schlüssel für wiederholbare
 * Schreibaufrufe und Datenexport-Aufträge der App.
 *
 * api_device_session und api_idempotency_key sind schulübergreifend (ein
 * Konto, mehrere Schulen) und haben deshalb keine tenant_id und keine RLS
 * -- wie account_setup_tokens; Zugriff nur über die eigene user_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_device_session', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->string('device_name', 100);
            $table->string('device_platform', 10)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->char('access_token_hash', 64)->unique();
            $table->timestampTz('access_expires_at');
            $table->char('refresh_token_hash', 64)->unique();
            $table->char('previous_refresh_token_hash', 64)->nullable()->index();
            $table->timestampTz('refresh_expires_at');
            $table->timestampTz('max_expires_at');
            $table->timestampTz('last_used_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('revoked_at')->nullable();
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('api_idempotency_key', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->uuid('idempotency_key');
            $table->char('fingerprint', 64);
            $table->unsignedSmallInteger('status_code');
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index('created_at');
        });

        Schema::create('api_data_export', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->string('status', 20);
            $table->string('path')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'user_id']);
            $table->index('user_id');
        });

        DB::statement('ALTER TABLE api_data_export ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY api_data_export_isolation ON api_data_export
            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)");

        if (AppRoleGrants::roleExistsOrWarn()) {
            foreach (['api_device_session', 'api_idempotency_key', 'api_data_export'] as $table) {
                DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$table} TO ".AppRoleGrants::quotedRole());
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_data_export');
        Schema::dropIfExists('api_idempotency_key');
        Schema::dropIfExists('api_device_session');
    }
};
