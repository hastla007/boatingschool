<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review B21: der bestehende Unique-Index liegt auf (tenant_id, code)
 * -- für globale Kurse (tenant_id IS NULL) verhindert das keine zwei Zeilen
 * mit demselben Code, weil Postgres NULLs in einem Unique-Index paarweise
 * als verschieden behandelt. CourseEditorController::store() validiert die
 * Eindeutigkeit bereits auf Anwendungsebene (Rule::unique(...)->whereNull
 * ('tenant_id')), das schützt aber nicht vor einem Wettlauf zwischen zwei
 * gleichzeitigen Anfragen oder einem direkten DB-Zugriff (Migration/
 * Seeder). Dieser partielle Index schließt genau die Lücke, ohne die
 * bestehende Mehrfachverwendung desselben Codes über verschiedene
 * Bootsschulen hinweg (tenant_id NOT NULL) einzuschränken.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX course_definition_global_code_unique ON course_definition (code) WHERE tenant_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS course_definition_global_code_unique');
    }
};
