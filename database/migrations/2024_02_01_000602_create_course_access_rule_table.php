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
        Schema::create('course_access_rule', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('course_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->foreign('course_id')->references('id')->on('course_definition')->cascadeOnDelete();
            $table->string('feature_code', 40);
            $table->string('access_type', 20);
            $table->string('module_code', 80)->nullable();
            $table->unsignedInteger('limit_value')->nullable();
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_until')->nullable();
            $table->string('source_key', 160)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'source_key']);
            $table->index(['tenant_id', 'course_id', 'feature_code', 'access_type']);
            $table->index(['valid_from', 'valid_until']);
        });
        DB::statement("ALTER TABLE course_access_rule ADD CONSTRAINT course_access_rule_feature_check CHECK (feature_code IN ('question_bank','learning_explanations','exam_simulation','navigation_trainer','praxis_trainer','video_course','radio_training','device_simulation','downloads'))");
        DB::statement("ALTER TABLE course_access_rule ADD CONSTRAINT course_access_rule_type_check CHECK (access_type IN ('free','free_limited','drip'))");
        DB::statement("ALTER TABLE course_access_rule ADD CONSTRAINT course_access_rule_limit_check CHECK ((access_type = 'free_limited' AND limit_value >= 0) OR (access_type = 'drip' AND limit_value > 0) OR (access_type = 'free' AND limit_value IS NULL))");
        DB::statement('ALTER TABLE course_access_rule ADD CONSTRAINT course_access_rule_date_check CHECK (valid_from IS NULL OR valid_until IS NULL OR valid_from <= valid_until)');

        $role = AppRoleGrants::quotedRole();
        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT ON course_access_rule TO {$role}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_access_rule');
    }
};
