<?php

namespace App\Console\Commands;

use App\Services\BackupVerifier;
use App\Support\BackupEncryption;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Probe-Wiederherstellung der neuesten lokalen Sicherung (monatlich per
 * Scheduler): Prüfsummen laut manifest.json, Dump in eine temporäre
 * Datenbank einspielen, Stichproben (wichtige Tabellen haben Zeilen,
 * Migrationen passen zur laufenden Datenbank), temporäre Datenbank wieder
 * löschen. Eine Sicherung, die sich nie zurückspielen ließ, ist keine.
 */
class VerifyBackup extends Command
{
    protected $signature = 'app:backup:verify {--path= : Bestimmter Sicherungsordner statt des neuesten}';

    protected $description = 'Spielt die neueste Sicherung probeweise in eine temporäre Datenbank ein und prüft sie.';

    public function handle(BackupVerifier $verifier): int
    {
        $dir = $this->option('path') ?: BackupApplication::latestBackupDirectory();
        if (! $dir || ! is_file($dir.'/manifest.json')) {
            $this->error('Keine vollständige Sicherung gefunden (manifest.json fehlt).');

            return self::FAILURE;
        }
        $this->info('Sicherung: '.$dir);

        $manifest = json_decode((string) File::get($dir.'/manifest.json'), true);
        $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        foreach ($files as $file => $meta) {
            $path = $dir.'/'.$file;
            if (! is_file($path) || hash_file('sha256', $path) !== ($meta['sha256'] ?? null)) {
                $this->error("Prüfsumme stimmt nicht oder Datei fehlt: {$file}");

                return self::FAILURE;
            }
        }
        $this->line('Prüfsummen: '.count($files).' Datei(en) in Ordnung.');

        $dumpFile = collect(array_keys($files))->first(fn ($f) => str_starts_with($f, 'database.dump'));
        if (! $dumpFile) {
            $this->error('Kein Datenbank-Dump in der Sicherung.');

            return self::FAILURE;
        }

        $workDir = storage_path('app/backup-verify-'.Str::random(8));
        File::ensureDirectoryExists($workDir, 0700);
        $database = $verifier->temporaryDatabaseName();
        $created = false;

        try {
            $dump = $dir.'/'.$dumpFile;
            if ($mode = BackupEncryption::modeOfFile($dumpFile)) {
                $plain = $workDir.'/database.dump';
                $command = BackupEncryption::decryptCommand($mode, $dump, $plain);
                if (! $command) {
                    // Der private Schlüssel gehört bewusst nicht auf den Server.
                    $this->warn("Sicherung ist mit {$mode} verschlüsselt und hier kein privater Schlüssel eingerichtet "
                        .'(BACKUP_AGE_IDENTITY bzw. BACKUP_GPG_DECRYPT): nur Prüfsummen geprüft, keine Probe-Wiederherstellung. '
                        .'Die Probe-Wiederherstellung auf einem Rechner mit Schlüssel ausführen (deploy/README.md).');

                    return self::SUCCESS;
                }
                $result = Process::timeout(3600)->run($command);
                if ($result->failed() || ! is_file($plain)) {
                    $this->error("Entschlüsseln fehlgeschlagen ({$mode}): ".trim($result->errorOutput()));

                    return self::FAILURE;
                }
                $dump = $plain;
            }

            try {
                $verifier->createDatabase($database);
                $created = true;
            } catch (Throwable $e) {
                $this->error('Temporäre Datenbank konnte nicht angelegt werden: '.Str::limit($e->getMessage(), 300));
                $this->line('Die Owner-Rolle braucht dafür das Recht CREATEDB (als postgres: ALTER ROLE <owner> CREATEDB), siehe deploy/README.md.');

                return self::FAILURE;
            }

            $verifier->restore($dump, $database);
            $result = $verifier->inspect($database, (array) config('backup.verify_tables', []));
            $live = $verifier->liveMigrations();

            return $this->evaluate($result, $live);
        } catch (Throwable $e) {
            report($e);
            $this->error('Probe-Wiederherstellung fehlgeschlagen: '.Str::limit($e->getMessage(), 500));

            return self::FAILURE;
        } finally {
            if ($created) {
                try {
                    $verifier->dropDatabase($database);
                } catch (Throwable $e) {
                    report($e);
                    $this->error("Temporäre Datenbank {$database} konnte nicht gelöscht werden: ".$e->getMessage());
                }
            }
            File::deleteDirectory($workDir);
        }
    }

    /**
     * @param  array{migrations: list<string>, counts: array<string, int|null>}  $result
     * @param  list<string>  $live
     */
    private function evaluate(array $result, array $live): int
    {
        $problems = [];
        $rows = [];
        foreach ($result['counts'] as $table => $count) {
            $rows[] = [$table, $count === null ? 'fehlt' : (string) $count];
            if (! $count) {
                $problems[] = $count === null ? "Tabelle {$table} fehlt" : "Tabelle {$table} ist leer";
            }
        }
        $this->table(['Tabelle', 'Zeilen'], $rows);

        $unknown = array_values(array_diff($result['migrations'], $live));
        $newer = array_values(array_diff($live, $result['migrations']));
        if ($result['migrations'] === []) {
            $problems[] = 'keine Migrationen in der Sicherung';
        }
        if ($unknown !== []) {
            // Die Sicherung kennt Migrationen, die die laufende Datenbank nicht hat:
            // falsche Datenbank gesichert oder Stand nicht passend zum Code.
            $problems[] = count($unknown).' Migration(en) der Sicherung fehlen in der laufenden Datenbank (z. B. '.$unknown[0].')';
        }
        $this->line('Migrationen: '.count($result['migrations']).' in der Sicherung, '.count($live).' in der laufenden Datenbank'
            .($newer !== [] ? ' ('.count($newer).' neuer als die Sicherung, z. B. nach einem Deploy)' : '').'.');

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error($problem);
            }

            return self::FAILURE;
        }
        $this->info('Sicherung lässt sich wiederherstellen.');

        return self::SUCCESS;
    }
}
