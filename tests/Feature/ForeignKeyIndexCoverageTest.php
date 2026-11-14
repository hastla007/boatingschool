<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P2: 69 von 105 Fremdschlüssel-Spalten hatten keinen eigenen Index --
 * weder JOINs/WHERE-Filter auf diese Spalten noch die beim Löschen der
 * referenzierten Zeile anfallende FK-Prüfung (Postgres sucht dafür ohne
 * Index per Sequential Scan über die GESAMTE Tabelle) konnten den Index
 * nutzen. Migration 000464 hat das nachgerüstet; dieser Test bewacht die
 * Invariante dauerhaft gegen künftige Migrationen, die eine neue FK-Spalte
 * ohne begleitenden Index hinzufügen.
 */
class ForeignKeyIndexCoverageTest extends TestCase
{
    public function test_every_single_column_foreign_key_has_a_covering_leading_index(): void
    {
        $sql = <<<'SQL'
            WITH fk_columns AS (
                SELECT
                    tc.table_name,
                    kcu.column_name,
                    tc.constraint_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                    ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = 'public'
            ),
            single_column_fks AS (
                SELECT table_name, MIN(column_name) AS column_name, constraint_name
                FROM fk_columns
                GROUP BY table_name, constraint_name
                HAVING COUNT(*) = 1
            ),
            indexed_leading_columns AS (
                SELECT
                    t.relname AS table_name,
                    a.attname AS column_name
                FROM pg_index ix
                JOIN pg_class t ON t.oid = ix.indrelid
                JOIN pg_class i ON i.oid = ix.indexrelid
                JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ix.indkey[0]
                JOIN pg_namespace n ON n.oid = t.relnamespace
                WHERE n.nspname = 'public'
            )
            SELECT DISTINCT f.table_name, f.column_name, f.constraint_name
            FROM single_column_fks f
            LEFT JOIN indexed_leading_columns idx
                ON idx.table_name = f.table_name AND idx.column_name = f.column_name
            WHERE idx.column_name IS NULL
            ORDER BY f.table_name, f.column_name
        SQL;

        $unindexed = DB::connection('pgsql_admin')->select($sql);

        $description = implode("\n", array_map(
            fn ($row) => "{$row->table_name}.{$row->column_name} ({$row->constraint_name})",
            $unindexed
        ));

        $this->assertEmpty(
            $unindexed,
            "Folgende Fremdschlüssel-Spalten haben keinen eigenen Index:\n{$description}"
        );
    }

    /**
     * Zusammengesetzte Fremdschlüssel (seit den Lerngruppen, z. B.
     * (tenant_id, user_id) -> tenant_user): ein Index muss mit genau den
     * Spalten des Schlüssels beginnen (Reihenfolge egal).
     */
    public function test_every_composite_foreign_key_has_a_covering_leading_index(): void
    {
        $db = DB::connection('pgsql_admin');
        $foreignKeys = $db->select(<<<'SQL'
            SELECT c.conrelid::regclass::text AS table_name, c.conname AS constraint_name, c.conrelid AS rel, c.conkey AS cols
            FROM pg_constraint c
            JOIN pg_namespace n ON n.oid = c.connamespace
            WHERE c.contype = 'f' AND n.nspname = 'public' AND array_length(c.conkey, 1) > 1
        SQL);

        $uncovered = [];
        foreach ($foreignKeys as $fk) {
            $covered = $db->selectOne(<<<'SQL'
                SELECT 1 AS ok FROM pg_index ix
                WHERE ix.indrelid = ?
                  AND (SELECT array_agg(k ORDER BY k) FROM unnest((ix.indkey::int2[])[0:array_length(?::int2[], 1) - 1]) k)
                    = (SELECT array_agg(k ORDER BY k) FROM unnest(?::int2[]) k)
                LIMIT 1
            SQL, [$fk->rel, $fk->cols, $fk->cols]);
            if (! $covered) {
                $uncovered[] = "{$fk->table_name} ({$fk->constraint_name})";
            }
        }

        $this->assertEmpty($uncovered, "Zusammengesetzte Fremdschlüssel ohne passenden Index:\n".implode("\n", $uncovered));
    }
}
