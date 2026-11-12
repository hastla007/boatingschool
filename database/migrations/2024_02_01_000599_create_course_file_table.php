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
        Schema::create('course_file', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('course_id');
            $table->foreign('course_id')->references('id')->on('course_definition')->cascadeOnDelete();
            $table->string('category', 20);
            $table->string('section_key', 120)->nullable();
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->text('storage_path');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('required_capability', 32)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('published')->default(false);
            $table->timestampsTz();
            $table->index(['course_id', 'category', 'published', 'sort_order']);
        });
        DB::statement("ALTER TABLE course_file ADD CONSTRAINT course_file_category_check CHECK (category IN ('download','section','chart'))");
        DB::statement("ALTER TABLE course_file ADD CONSTRAINT course_file_capability_check CHECK (required_capability IS NULL OR required_capability IN ('smarttrainer','favorites','exam','video','navigation','praxis','funk'))");
        DB::statement("ALTER TABLE course_file ADD CONSTRAINT course_file_path_check CHECK (storage_path <> '' AND storage_path !~ '(^/|(^|/)\.\.(/|$)|^[A-Za-z][A-Za-z0-9+.-]*://)')");

        $role = AppRoleGrants::quotedRole();
        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT ON course_file TO {$role}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_file');
    }
};
