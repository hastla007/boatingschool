<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Native group licences and personal invitations for the standalone platform. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_group', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('owner_user_id');
            $table->foreign('owner_user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('seats');
            $table->jsonb('scopes');
            $table->timestampTz('valid_from')->useCurrent();
            $table->timestampTz('valid_until')->nullable();
            $table->string('join_code', 33)->unique();
            $table->string('source_type', 40)->default('manual');
            $table->string('source_reference', 160)->nullable();
            $table->timestampsTz();
            $table->index(['owner_user_id', 'created_at']);
        });
        DB::statement("ALTER TABLE learning_group ADD CONSTRAINT learning_group_status_check CHECK (status IN ('active','suspended','revoked'))");
        DB::statement('ALTER TABLE learning_group ADD CONSTRAINT learning_group_seats_check CHECK (seats > 0)');
        DB::statement('ALTER TABLE learning_group ADD CONSTRAINT learning_group_valid_range_check CHECK (valid_until IS NULL OR valid_until > valid_from)');

        Schema::create('learning_group_member', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('group_id');
            $table->foreign('group_id')->references('id')->on('learning_group')->cascadeOnDelete();
            $table->uuid('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('app_user')->nullOnDelete();
            $table->string('email', 320)->nullable();
            $table->string('status', 20)->default('invited');
            $table->string('invite_token', 33)->nullable()->unique();
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('joined_at')->nullable();
            $table->timestampsTz();
            $table->index(['group_id', 'status']);
            $table->index(['user_id', 'status']);
        });
        DB::statement("ALTER TABLE learning_group_member ADD CONSTRAINT learning_group_member_status_check CHECK (status IN ('invited','active','removed','expired'))");
        DB::statement("CREATE UNIQUE INDEX learning_group_member_email_unique ON learning_group_member(group_id, lower(email)) WHERE email IS NOT NULL AND status IN ('invited','active')");

        $appRole = AppRoleGrants::quotedRole();
        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON learning_group, learning_group_member TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_group_member');
        Schema::dropIfExists('learning_group');
    }
};
