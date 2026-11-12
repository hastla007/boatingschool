<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_mobile_web_session', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('user_id');
            $table->uuid('api_device_session_id');
            $table->char('code_hash', 64)->unique();
            $table->char('verifier_hash', 64);
            $table->text('redirect_path');
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_id', 'api_device_session_id', 'expires_at']);
            $table->index('expires_at');
        });

        $role = AppRoleGrants::quotedRole();
        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON api_mobile_web_session TO {$role}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_mobile_web_session');
    }
};
