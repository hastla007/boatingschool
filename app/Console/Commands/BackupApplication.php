<?php

namespace App\Console\Commands;

use App\Support\BackupEncryption;
use Aws\S3\S3Client;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Datensicherung: Datenbank (pg_dump, Custom-Format, über die
 * Owner-Verbindung) und Uploads (storage/app/public als ZIP) in einen
 * Ordner mit Zeitstempel, optional zusätzlich auf einen externen Speicher
 * (BACKUP_DISK). Optional asymmetrisch verschlüsselt (age bzw. GnuPG, siehe
 * BackupEncryption). Alte lokale Sicherungen werden nach BACKUP_KEEP_DAYS
 * gelöscht, externe nur mit BACKUP_REMOTE_PRUNE=true (sonst regeln das
 * Lifecycle-Regeln des Buckets, siehe deploy/README.md).
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

        $offsiteSkipped = null;
        try {
            // Verschlüsselung vorab klären: fehlt das Werkzeug, gar nicht erst dumpen.
            $encryption = BackupEncryption::mode();

            $files = ['database.dump' => $this->dumpDatabase($dir.'/database.dump')];
            if (! $this->option('no-uploads')) {
                $files['uploads.zip'] = $this->zipUploads($dir.'/uploads.zip');
            }
            $files = array_filter($files);
            if ($encryption) {
                $files = $this->encrypt($files, $encryption);
            }

            $manifest = [
                'created_at' => now()->toIso8601String(),
                'app' => config('app.name'),
                'encryption' => $encryption,
                'files' => collect($files)->map(fn ($path) => ['bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)])->all(),
            ];
            File::put($dir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $files['manifest.json'] = $dir.'/manifest.json';

            $disk = config('backup.disk');
            $offsiteSkipped = $disk ? self::offsiteUnavailableReason($disk) : null;
            if ($disk && ! $offsiteSkipped) {
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
        $this->info("Sicherung erstellt: {$dir}".($encryption ? " (verschlüsselt mit {$encryption})" : '').($pruned ? " ({$pruned} alte Sicherung(en) gelöscht)" : ''));

        if ($offsiteSkipped) {
            // Die lokale Sicherung ist vollständig; nur die externe Kopie fehlt.
            report(new RuntimeException('Externe Kopie der Sicherung übersprungen: '.$offsiteSkipped));
            $this->warn('Externe Kopie übersprungen: '.$offsiteSkipped);
        }

        return self::SUCCESS;
    }

    /**
     * Warum der externe Speicher nicht nutzbar ist (null = nutzbar). Die
     * s3-Disk braucht das Paket league/flysystem-aws-s3-v3 (in composer.json);
     * fehlt es (z. B. composer install mit altem Lockfile), bleibt es bei der
     * lokalen Sicherung statt eines Abbruchs.
     */
    public static function offsiteUnavailableReason(string $disk): ?string
    {
        // Der Adapter-Quelltext liegt auch im Kernpaket league/flysystem; erst
        // das AWS-SDK (Abhängigkeit von league/flysystem-aws-s3-v3) fehlt dann.
        if (config("filesystems.disks.{$disk}.driver") === 's3' && ! self::s3PackageInstalled()) {
            return 'Paket league/flysystem-aws-s3-v3 fehlt (composer install ausführen).';
        }

        return null;
    }

    public static function s3PackageInstalled(): bool
    {
        return class_exists(AwsS3V3Adapter::class) && class_exists(S3Client::class);
    }

    /**
     * Dateien verschlüsseln (Klartext danach löschen).
     *
     * @param  array<string, string>  $files  Name => Pfad
     * @return array<string, string>
     */
    private function encrypt(array $files, string $mode): array
    {
        $encrypted = [];
        foreach ($files as $file => $path) {
            $target = $path.BackupEncryption::extension($mode);
            $result = Process::timeout(3600)->run(BackupEncryption::encryptCommand($mode, $path, $target));
            // Klartext auf jeden Fall entfernen, auch wenn die Verschlüsselung scheitert.
            File::delete($path);
            if ($result->failed() || ! is_file($target) || filesize($target) === 0) {
                throw new RuntimeException("{$mode}: ".trim($result->errorOutput() ?: 'keine Ausgabe'));
            }
            $encrypted[$file.BackupEncryption::extension($mode)] = $target;
        }

        return $encrypted;
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

    /** Sicherungen älter als BACKUP_KEEP_DAYS löschen (lokal; extern nur mit BACKUP_REMOTE_PRUNE). */
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
        // Extern nur auf ausdrücklichen Wunsch löschen (BACKUP_REMOTE_PRUNE):
        // sonst regeln Lifecycle-Regeln des Buckets die Aufbewahrung, und die
        // Zugangsdaten auf dem Server brauchen kein Löschrecht.
        $disk = config('backup.disk');
        if ($disk && config('backup.remote_prune') && ! self::offsiteUnavailableReason($disk)) {
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
        $dir = self::latestBackupDirectory();

        return $dir ? Carbon::createFromFormat('Y-m-d_His', basename($dir)) : null;
    }

    /** Ordner der neuesten vollständigen lokalen Sicherung (mit manifest.json). */
    public static function latestBackupDirectory(): ?string
    {
        $base = rtrim(config('backup.path'), '/');
        if (! is_dir($base)) {
            return null;
        }

        return collect(File::directories($base))
            ->filter(fn ($dir) => is_file($dir.'/manifest.json'))
            ->filter(fn ($dir) => preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', basename($dir)))
            ->sortBy(fn ($dir) => basename($dir))->last();
    }
}
