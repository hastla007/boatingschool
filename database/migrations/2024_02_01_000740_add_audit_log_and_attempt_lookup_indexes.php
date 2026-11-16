<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit ARC-3/ARC-4: audit_log hatte nur (tenant_id, created_at) -- Suchen
 * nach einer Entität (Konto löschen, Schule offboarden: entity_type +
 * entity_id), die plattformweite Protokollliste (created_at DESC, id DESC)
 * und das Aufräumen nach Frist (created_at < …) liefen als Seq-Scan über
 * die ganze Tabelle; ebenso attempt beim Aufräumen nach created_at.
 *
 * Der Index (created_at DESC, id DESC) dient zugleich dem Aufräumen von
 * audit_log (Bereichsabfrage, B-Baum in beide Richtungen lesbar).
 *
 * CONCURRENTLY: die Tabellen sind groß und werden laufend beschrieben, ein
 * normales CREATE INDEX sperrte Schreibzugriffe für die ganze Bauzeit.
 * CONCURRENTLY geht nicht in einer Transaktion -- deshalb ohne. IF NOT
 * EXISTS: ein abgebrochener Lauf hinterlässt höchstens einen ungültigen
 * Index, der vorher entfernt wird, damit ein erneuter Lauf ihn neu baut.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'idx_audit_log_entity' => 'audit_log (entity_type, entity_id)',
        'idx_audit_log_time' => 'audit_log (created_at DESC, id DESC)',
        'idx_attempt_created_at' => 'attempt (created_at)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $definition) {
            $invalid = DB::selectOne(
                'SELECT 1 AS x FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND NOT i.indisvalid',
                [$name]
            );
            if ($invalid) {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            }
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$definition}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }
};
