<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Review-Runde 11: Aufgabentext, Hinweis und Karte der Navigationsaufgabe
 * zum Prüfungsstart festhalten -- wie die Teilfragen (siehe 000588).
 * Vorher sah ein Teilnehmer nach einer Bearbeitung durch den Superadmin
 * den neuen Aufgabentext zu den alten Teilfragen und Musterlösungen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_session', function (Blueprint $table) {
            $table->jsonb('navigation_task_snapshot')->nullable();
        });

        DB::statement("UPDATE exam_session s SET navigation_task_snapshot = jsonb_build_object(
                'task_number', t.task_number, 'scenario_text', t.scenario_text, 'hint', t.hint,
                'chart_image_path', t.chart_image_path, 'chart_caption', t.chart_caption)
            FROM navigation_task t WHERE t.id = s.navigation_task_id");
    }

    public function down(): void
    {
        Schema::table('exam_session', function (Blueprint $table) {
            $table->dropColumn('navigation_task_snapshot');
        });
    }
};
