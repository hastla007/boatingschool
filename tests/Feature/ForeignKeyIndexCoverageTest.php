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
                SELECT table_name, column_name, constraint_name
                FROM fk_columns
                GROUP BY table_name, column_name, constraint_name
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

    public function test_the_schema_has_no_composite_foreign_keys_that_the_single_column_check_above_would_miss(): void
    {
        $composite = DB::connection('pgsql_admin')->select(<<<'SQL'
            SELECT tc.table_name, tc.constraint_name, COUNT(*) AS col_count
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = 'public'
            GROUP BY tc.table_name, tc.constraint_name
            HAVING COUNT(*) > 1
        SQL);

        $this->assertEmpty(
            $composite,
            'Es gibt jetzt zusammengesetzte Fremdschlüssel -- ForeignKeyIndexCoverageTest::test_every_single_column_foreign_key_has_a_covering_leading_index() prüft nur einspaltige FKs und muss um diese erweitert werden.'
        );
    }
}
