<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lastmessung große Bootsschule (1200 Lernende, 360 000 Versuche): die
 * schulweiten Zeitfenster-Abfragen auf attempt -- „aktiv diese Woche“ auf
 * /admin, „aktiv in 30 Tagen“, „Antworten in 30 Tagen“ und „aktiv je Monat“
 * auf /admin/statistics -- filtern nach tenant_id + created_at, aber kein
 * Index passte dazu: (tenant_id, course_id, created_at) braucht den Kurs,
 * (tenant_id, user_id, question_id, created_at) die Frage, (created_at)
 * liest die Versuche ALLER Schulen im Zeitfenster und muss jede Zeile im
 * Heap nachprüfen. „Aktiv diese Woche“ dauerte so 53 ms statt 18 ms, mit
 * jeder weiteren Schule auf der Plattform wächst der Abstand.
 *
 * INCLUDE (user_id): die Abfragen zählen nur verschiedene Personen, ein
 * Index-Only-Scan reicht dann.
 *
 * CONCURRENTLY wie 2024_02_01_000740: attempt ist die am häufigsten
 * beschriebene Tabelle, ein normales CREATE INDEX sperrte Schreibzugriffe
 * für die ganze Bauzeit. Ein abgebrochener Lauf hinterlässt höchstens einen
 * ungültigen Index, der vorher entfernt wird.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const NAME = 'idx_attempt_tenant_time';

    public function up(): void
    {
        $invalid = DB::selectOne(
            'SELECT 1 AS x FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND NOT i.indisvalid',
            [self::NAME]
        );
        if ($invalid) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::NAME);
        }
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::NAME.' ON attempt (tenant_id, created_at) INCLUDE (user_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::NAME);
    }
};
