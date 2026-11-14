<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kursdateien der Mobile-API (Downloads, Abschnittsdateien, Karten aus der
 * WP-App). Plattformweite Inhalte der Plattformkurse ohne Schulbezug: daher
 * ohne tenant_id/RLS, die App-Rolle darf nur lesen (Import über die
 * Owner-Verbindung). Die Dateien liegen auf der privaten Disk unter
 * course-files/ und werden nur über GET /api/v1/files/{id} mit
 * Zugangsprüfung ausgeliefert.
 *
 * Enthält auch die Spalten der früheren Entwürfe 000603 (Capability
 * downloads) und 000604 (source_key), die nie ausgeliefert wurden.
 */
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
            $table->string('source_key', 160)->nullable();
            $table->timestampsTz();
            $table->index(['course_id', 'category', 'published', 'sort_order']);
            $table->unique(['course_id', 'source_key']);
        });
        DB::statement("ALTER TABLE course_file ADD CONSTRAINT course_file_category_check CHECK (category IN ('download','section','chart'))");
        DB::statement("ALTER TABLE course_file ADD CONSTRAINT course_file_required_capability_check CHECK (required_capability IS NULL OR required_capability IN ('smarttrainer','favorites','exam','video','navigation','praxis','funk','downloads'))");
        // Nur relative Pfade unter course-files/, ohne ".."-Segmente.
        DB::statement("ALTER TABLE course_file ADD CONSTRAINT course_file_path_check CHECK (storage_path LIKE 'course-files/%' AND storage_path !~ '(^|/)\\.\\.(/|$)' AND storage_path !~ '[\\\\[:cntrl:]]')");

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement('GRANT SELECT ON course_file TO '.AppRoleGrants::quotedRole());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_file');
    }
};
