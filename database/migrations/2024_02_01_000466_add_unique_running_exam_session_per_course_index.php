<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review N20: dasselbe Doppelklick-Problem wie B4 (siehe Migration
 * 000460), nur für die zufällig zusammengestellte Prüfung ohne Bogen
 * (ExamController::start(), paper_id ist dort immer NULL) -- dafür gab es
 * bisher weder einen Advisory-Lock noch diesen Unique-Index. Der
 * eigentliche Fix sperrt den Vorgang jetzt per Advisory-Lock in start();
 * dieser Unique-Index ist zusätzliche Verteidigung auf Datenbankebene.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX exam_session_running_random_unique ON exam_session (tenant_id, user_id, course_id)
            WHERE status = 'running' AND paper_id IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS exam_session_running_random_unique');
    }
};
