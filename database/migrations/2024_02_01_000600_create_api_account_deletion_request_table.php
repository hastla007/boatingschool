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
        Schema::create('api_account_deletion_request', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('user_id')->index();
            $table->char('confirm_key_hash', 64);
            $table->string('status', 32)->default('pending_confirmation');
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->index(['user_id', 'status', 'requested_at']);
            $table->index('expires_at');
        });
        DB::statement("ALTER TABLE api_account_deletion_request ADD CONSTRAINT api_account_deletion_request_status_check CHECK (status IN ('pending_confirmation','confirmed'))");

        $role = AppRoleGrants::quotedRole();
        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE ON api_account_deletion_request TO {$role}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_account_deletion_request');
    }
};
