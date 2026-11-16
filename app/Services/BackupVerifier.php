<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Datenbank-Schritte von app:backup:verify: temporäre Datenbank anlegen,
 * Dump hineinspielen, Stichproben lesen, wieder löschen. Läuft über die
 * Owner-Rolle (sie besitzt die Tabellen; der Dump enthält RLS-Policies und
 * Rechte). CREATE DATABASE braucht das Recht CREATEDB für diese Rolle,
 * siehe deploy/README.md.
 */
class BackupVerifier
{
    private const CONNECTION = 'backup_verify';

    /** Eindeutiger Name für die temporäre Datenbank (abgeleitet von der echten). */
    public function temporaryDatabaseName(): string
    {
        $base = preg_replace('/[^a-z0-9_]/', '_', strtolower((string) config('database.connections.pgsql_admin.database')));

        return substr($base, 0, 30).'_verify_'.now()->format('YmdHis').'_'.Str::lower(Str::random(4));
    }

    public function createDatabase(string $name): void
    {
        DB::connection('pgsql_admin')->statement('CREATE DATABASE '.$this->quote($name).' TEMPLATE template0');
    }

    public function restore(string $dumpPath, string $name): void
    {
        $db = config('database.connections.pgsql_admin');
        $result = Process::timeout(3600)
            ->env(['PGPASSWORD' => (string) $db['password']])
            ->run([
                (string) config('backup.pg_restore', 'pg_restore'), '--exit-on-error',
                '--host='.$db['host'], '--port='.$db['port'], '--username='.$db['username'],
                '--dbname='.$name, $dumpPath,
            ]);

        if ($result->failed()) {
            throw new RuntimeException('pg_restore: '.trim($result->errorOutput() ?: 'keine Ausgabe'));
        }
    }

    /**
     * @param  list<string>  $tables
     * @return array{migrations: list<string>, counts: array<string, int|null>} null = Tabelle fehlt
     */
    public function inspect(string $name, array $tables): array
    {
        config(['database.connections.'.self::CONNECTION => array_merge(config('database.connections.pgsql_admin'), ['database' => $name])]);
        DB::purge(self::CONNECTION);
        $connection = DB::connection(self::CONNECTION);

        try {
            $counts = [];
            foreach ($tables as $table) {
                $exists = $connection->selectOne('SELECT to_regclass(?) AS t', ['public.'.$table])?->t;
                $counts[$table] = $exists ? (int) $connection->table($table)->count() : null;
            }
            $migrations = $connection->selectOne("SELECT to_regclass('public.migrations') AS t")?->t
                ? $connection->table('migrations')->pluck('migration')->all()
                : [];

            return ['migrations' => $migrations, 'counts' => $counts];
        } finally {
            DB::purge(self::CONNECTION);
        }
    }

    public function dropDatabase(string $name): void
    {
        DB::purge(self::CONNECTION);
        // WITH (FORCE): auch falls noch eine Verbindung offen ist (PostgreSQL 13+).
        DB::connection('pgsql_admin')->statement('DROP DATABASE IF EXISTS '.$this->quote($name).' WITH (FORCE)');
    }

    /** @return list<string> Migrationen der laufenden Datenbank */
    public function liveMigrations(): array
    {
        return DB::connection('pgsql_admin')->table('migrations')->pluck('migration')->all();
    }

    private function quote(string $name): string
    {
        if (! preg_match('/^[a-z0-9_]{1,63}$/', $name)) {
            throw new InvalidArgumentException('Ungültiger Datenbankname.');
        }

        return '"'.$name.'"';
    }
}
