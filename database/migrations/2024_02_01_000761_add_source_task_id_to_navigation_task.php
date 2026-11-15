<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * QA-L-6: Kombikurse haben gespiegelte Kopien der SBF-See-Navigations-
 * aufgaben (ComboNavigationTaskSeeder) mit eigenen IDs -- die Sperre der
 * Aufgabe einer laufenden Prüfung verglich nur die ID, über die Kopie im
 * anderen Kurs ließ sich die Musterlösung abrufen. source_task_id verknüpft
 * Kopie und Quelle; der Bestand wird über die Aufgabennummer nachgetragen.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE navigation_task ADD COLUMN IF NOT EXISTS source_task_id uuid NULL');
        DB::statement('ALTER TABLE navigation_task DROP CONSTRAINT IF EXISTS navigation_task_source_task_id_foreign');
        DB::statement('ALTER TABLE navigation_task ADD CONSTRAINT navigation_task_source_task_id_foreign
            FOREIGN KEY (source_task_id) REFERENCES navigation_task(id) ON DELETE SET NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS navigation_task_source_task_id_index ON navigation_task (source_task_id)');

        DB::statement("UPDATE navigation_task t SET source_task_id = (
                SELECT s.id FROM navigation_task s
                JOIN course_definition sc ON sc.id = s.course_id AND sc.code = 'SBF-SEE' AND sc.tenant_id IS NULL
                WHERE s.task_number = t.task_number
                ORDER BY s.sort_order, s.id LIMIT 1)
            FROM course_definition tc
            WHERE tc.id = t.course_id AND tc.code IN ('SBF-SEE-BIN', 'ADD-BIN-TO-SEE') AND tc.tenant_id IS NULL
                AND t.source_task_id IS NULL");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE navigation_task DROP COLUMN IF EXISTS source_task_id');
    }
};
