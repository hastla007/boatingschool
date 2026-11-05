<?php

namespace App\Console\Commands;

use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Datensicherung: Datenbank (pg_dump, Custom-Format, über die
 * Owner-Verbindung) und Uploads (storage/app/public als ZIP) in einen
 * Ordner mit Zeitstempel, optional zusätzlich auf einen externen Speicher
 * (BACKUP_DISK). Alte Sicherungen werden nach BACKUP_KEEP_DAYS gelöscht.
 *
 * Wiederherstellen: siehe deploy/README.md („Backup zurückspielen“).
 */
class BackupApplication extends Command
{
    protected $signature = 'app:backup {--no-uploads : Nur die Datenbank sichern}';

    protected $description = 'Sichert Datenbank und Uploads (täglich per Scheduler).';

    public function handle(): int
    {
        $base = rtrim(config('backup.path'), '/');
        $name = now()->format('Y-m-d_His');
        $dir = $base.'/'.$name;
        File::ensureDirectoryExists($dir, 0700);

        try {
            $files = ['database.dump' => $this->dumpDatabase($dir.'/database.dump')];
            if (! $this->option('no-uploads')) {
                $files['uploads.zip'] = $this->zipUploads($dir.'/uploads.zip');
            }
            $files = array_filter($files);

            $manifest = [
                'created_at' => now()->toIso8601String(),
                'app' => config('app.name'),
                'files' => collect($files)->map(fn ($path) => ['bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)])->all(),
            ];
            File::put($dir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $files['manifest.json'] = $dir.'/manifest.json';

            if ($disk = config('backup.disk')) {
                foreach ($files as $file => $path) {
                    $stream = fopen($path, 'r');
                    // Die s3-Disk wirft standardmäßig keine Fehler ('throw' => false), sondern liefert false.
                    $stored = Storage::disk($disk)->put("backups/{$name}/{$file}", $stream);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                    if (! $stored) {
                        throw new RuntimeException("Kopie auf Speicher „{$disk}“ fehlgeschlagen ({$file}).");
                    }
                }
                $this->info("Auf Speicher „{$disk}“ kopiert.");
            }
        } catch (Throwable $e) {
            File::deleteDirectory($dir);
            report($e);
            $this->error('Sicherung fehlgeschlagen: '.$e->getMessage());

            return self::FAILURE;
        }

        $pruned = $this->prune($base);
        $this->info("Sicherung erstellt: {$dir}".($pruned ? " ({$pruned} alte Sicherung(en) gelöscht)" : ''));

        return self::SUCCESS;
    }

    private function dumpDatabase(string $target): string
    {
        $db = config('database.connections.pgsql_admin');
        $result = Process::timeout(3600)
            ->env(['PGPASSWORD' => (string) $db['password']])
            ->run([
                config('backup.pg_dump'), '--format=custom',
                '--host='.$db['host'], '--port='.$db['port'], '--username='.$db['username'],
                '--file='.$target, $db['database'],
            ]);

        if ($result->failed() || ! is_file($target) || filesize($target) === 0) {
            throw new RuntimeException('pg_dump: '.trim($result->errorOutput() ?: 'keine Ausgabe'));
        }

        return $target;
    }

    private function zipUploads(string $target): ?string
    {
        $source = Storage::disk('public')->path('');
        if (! is_dir($source)) {
            return null;
        }

        $zip = new ZipArchive;
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP-Datei konnte nicht angelegt werden.');
        }
        foreach (File::allFiles($source, true) as $file) {
            if ($file->getFilename() === '.gitignore') {
                continue;
            }
            $zip->addFile($file->getPathname(), ltrim(str_replace('\\', '/', $file->getRelativePathname()), '/'));
        }
        $count = $zip->numFiles;
        $zip->close();

        return $count > 0 ? $target : null;
    }

    /** Sicherungen älter als BACKUP_KEEP_DAYS löschen (lokal und extern). */
    private function prune(string $base): int
    {
        $cutoff = now()->subDays(max(1, (int) config('backup.keep_days')))->format('Y-m-d_His');
        $pruned = 0;
        foreach (File::directories($base) as $dir) {
            $name = basename($dir);
            if (preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $name) && $name < $cutoff) {
                File::deleteDirectory($dir);
                $pruned++;
            }
        }
        if ($disk = config('backup.disk')) {
            foreach (Storage::disk($disk)->directories('backups') as $dir) {
                $name = basename($dir);
                if (preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $name) && $name < $cutoff) {
                    Storage::disk($disk)->deleteDirectory($dir);
                }
            }
        }

        return $pruned;
    }

    /** Zeitpunkt der neuesten vollständigen lokalen Sicherung (für app:check-production). */
    public static function latestBackupAt(): ?CarbonInterface
    {
        $base = rtrim(config('backup.path'), '/');
        if (! is_dir($base)) {
            return null;
        }
        $latest = collect(File::directories($base))
            ->filter(fn ($dir) => is_file($dir.'/manifest.json'))
            ->map(fn ($dir) => basename($dir))
            ->filter(fn ($name) => preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $name))
            ->sort()->last();

        return $latest ? Carbon::createFromFormat('Y-m-d_His', $latest) : null;
    }
}
