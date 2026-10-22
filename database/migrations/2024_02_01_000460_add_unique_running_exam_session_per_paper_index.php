<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review B4: ExamController::startPaper() prüfte bisher per Lesen-dann-
 * Schreiben, ob für diesen Bogen bereits eine laufende Session existiert --
 * ein Doppelklick auf "Jetzt starten" konnte dadurch zwei "running"-Sessions
 * für denselben Bogen anlegen. Der eigentliche Fix sperrt den Vorgang jetzt
 * per Advisory-Lock in startPaper(); dieser Unique-Index ist zusätzliche
 * Verteidigung auf Datenbankebene (gleiches Muster wie H1 bei den
 * Coupon-Unique-Indizes).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX exam_session_running_paper_unique ON exam_session (tenant_id, user_id, paper_id)
            WHERE status = 'running' AND paper_id IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS exam_session_running_paper_unique');
    }
};
