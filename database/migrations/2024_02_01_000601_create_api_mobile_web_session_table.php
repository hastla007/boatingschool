<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile-API: Einmal-Codes, mit denen die App die eingebettete Webansicht
 * einer Bootsschule anmeldet (60 Sekunden, einmalig, an Gerätesitzung und
 * Schule gebunden). Code und Verifier nur als SHA-256-Hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_mobile_web_session', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->uuid('api_device_session_id');
            $table->foreign('api_device_session_id')->references('id')->on('api_device_session')->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->char('verifier_hash', 64);
            $table->string('redirect_path', 400);
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'user_id']);
            $table->index('user_id');
            $table->index('api_device_session_id');
            $table->index('expires_at');
        });

        DB::statement('ALTER TABLE api_mobile_web_session ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY api_mobile_web_session_isolation ON api_mobile_web_session
            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)");

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON api_mobile_web_session TO '.AppRoleGrants::quotedRole());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_mobile_web_session');
    }
};
