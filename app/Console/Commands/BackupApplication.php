<?php

namespace App\Console\Commands;

use App\Models\CourseFile;
use App\Support\BackupEncryption;
use App\Support\NavigationChart;
use App\Support\QuestionMedia;
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
 * Owner-Verbindung), Uploads (storage/app/public als ZIP) und geschützte
 * Dateien der privaten Disk (Fragenbilder, Kartenausschnitte, Kursdateien)
 * in einen Ordner mit Zeitstempel, optional zusätzlich auf einen externen
 * Speicher (BACKUP_DISK), dort auf Wunsch clientseitig verschlüsselt
 * (BACKUP_ENCRYPTION_PUBLIC_KEY, siehe BackupEncryption). Alte Sicherungen
 * werden nach BACKUP_KEEP_DAYS gelöscht, extern nur mit BACKUP_PRUNE_REMOTE.
 *
 * Mit --deploy (deploy/deploy.sh): Sicherung nur lokal für den Rückweg, mit
 * eigenem Namen (…-deploy); davon bleiben nur die letzten BACKUP_KEEP_DEPLOY.
 *
 * Wiederherstellen: siehe deploy/README.md („Backup zurückspielen“).
 */
class BackupApplication extends Command
{
    /**
     * Ordner der privaten Disk, die immer zur Sicherung gehören (siehe
     * QuestionMedia, NavigationChart, CourseFile). Weitere Ordner der privaten
     * Disk kommen automatisch dazu, außer den kurzlebigen in EXCLUDED_PRIVATE_DIRECTORIES.
     */
    public const PRIVATE_MEDIA_DIRECTORIES = [QuestionMedia::DIRECTORY, NavigationChart::DIRECTORY, CourseFile::DIRECTORY];

    /** Kurzlebige Dateien der privaten Disk: Datenexporte (personenbezogen, Stunden gültig) und Import-Uploads. */
    public const EXCLUDED_PRIVATE_DIRECTORIES = ['api-exports', 'exports', 'imports', 'tmp', 'livewire-tmp'];

    /** Merker im Sicherungsordner: externe Kopie vollständig (für app:check-production). */
    public const REMOTE_MARKER = 'remote.json';

    /** Ordnername der täglichen Sicherung (Zeitstempel); Deploy-Sicherungen tragen zusätzlich DEPLOY_SUFFIX. */
    private const NAME_PATTERN = '/^\d{4}-\d{2}-\d{2}_\d{6}$/';

    private const DEPLOY_SUFFIX = '-deploy';

    protected $signature = 'app:backup
        {--no-uploads : Nur die Datenbank sichern}
        {--deploy : Sicherung vor einem Deploy (nur lokal, nur die letzten BACKUP_KEEP_DEPLOY bleiben)}';

    protected $description = 'Sichert Datenbank und Uploads (täglich per Scheduler).';

    public function handle(): int
    {
        $base = rtrim(config('backup.path'), '/');
        $deploy = (bool) $this->option('deploy');
        $name = now()->format('Y-m-d_His').($deploy ? self::DEPLOY_SUFFIX : '');
        $dir = $base.'/'.$name;
        File::ensureDirectoryExists($dir, 0700);

        try {
            $files = ['database.dump' => $this->dumpDatabase($dir.'/database.dump')];
            if (! $this->option('no-uploads')) {
                $files['uploads.zip'] = $this->zipUploads($dir.'/uploads.zip');
                // Geschützte Fragenbilder, Kartenausschnitte und Kursdateien (private Disk, ohne Exporte o. Ä.).
                $files['protected-media.zip'] = $this->zipUploads($dir.'/protected-media.zip', Storage::disk('local')->path(''), self::privateDirectories());
            }
            $files = array_filter($files);

            $manifest = [
                'created_at' => now()->toIso8601String(),
                'app' => config('app.name'),
                'files' => collect($files)->map(fn ($path) => ['bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)])->all(),
            ];
            File::put($dir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $files['manifest.json'] = $dir.'/manifest.json';
        } catch (Throwable $e) {
            File::deleteDirectory($dir);
            report($e);
            $this->error('Sicherung fehlgeschlagen: '.$e->getMessage());

            return self::FAILURE;
        }

        $disk = $deploy ? null : config('backup.disk');
        if ($disk) {
            try {
                $this->copyToDisk($disk, $name, $dir, $files);
            } catch (Throwable $e) {
                // Die lokale Sicherung ist vollständig und bleibt; nur die externe Kopie fehlt.
                // FAILURE löst den Betriebsalarm aus (routes/console.php), der Go-live-Check meldet es.
                report($e);
                $this->error("Sicherung lokal erstellt ({$dir}), aber die externe Kopie ist fehlgeschlagen: ".$e->getMessage());
                $this->prune($base, false);

                return self::FAILURE;
            }
        }

        $pruned = $this->prune($base, (bool) $disk);
        $this->info("Sicherung erstellt: {$dir}".($pruned ? " ({$pruned} alte Sicherung(en) gelöscht)" : ''));

        return self::SUCCESS;
    }

    /**
     * Zu sichernde Ordner der privaten Disk: die bekannten geschützten plus
     * alle weiteren vorhandenen, außer kurzlebigen Exporten/Importen und
     * einem dort ggf. liegenden Sicherungsordner.
     *
     * @return list<string>
     */
    public static function privateDirectories(): array
    {
        $root = rtrim(Storage::disk('local')->path(''), '/\\');
        $backup = realpath((string) config('backup.path')) ?: null;
        $found = collect(is_dir($root) ? File::directories($root) : [])
            ->reject(function ($dir) use ($backup) {
                $real = realpath($dir);

                return $backup !== null && $real !== false && ($real === $backup || str_starts_with($backup, $real.DIRECTORY_SEPARATOR));
            })
            ->map(fn ($dir) => basename($dir));

        return collect(self::PRIVATE_MEDIA_DIRECTORIES)->merge($found)
            ->reject(fn ($dir) => in_array($dir, self::EXCLUDED_PRIVATE_DIRECTORIES, true) || str_starts_with($dir, '.'))
            ->unique()->sort()->values()->all();
    }

    /** @param  array<string, string>  $files */
    private function copyToDisk(string $disk, string $name, string $dir, array $files): void
    {
        $publicKey = (string) config('backup.encryption_public_key');
        $encrypted = $publicKey !== '';
        foreach ($files as $file => $path) {
            $upload = $path;
            $target = $file;
            // Das Manifest (nur Größen/Prüfsummen) bleibt lesbar, alles andere geht verschlüsselt hinaus.
            if ($encrypted && $file !== 'manifest.json') {
                $upload = $path.BackupEncryption::EXTENSION;
                $target = $file.BackupEncryption::EXTENSION;
                BackupEncryption::encryptFile($path, $upload, $publicKey);
            }
            try {
                $stream = fopen($upload, 'r');
                // Die s3-Disk wirft standardmäßig keine Fehler ('throw' => false), sondern liefert false.
                $stored = Storage::disk($disk)->put("backups/{$name}/{$target}", $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            } finally {
                if ($upload !== $path) {
                    File::delete($upload);
                }
            }
            if (! $stored) {
                throw new RuntimeException("Kopie auf Speicher „{$disk}“ fehlgeschlagen ({$target}).");
            }
        }
        File::put($dir.'/'.self::REMOTE_MARKER, json_encode(['disk' => $disk, 'copied_at' => now()->toIso8601String(), 'encrypted' => $encrypted]));
        $this->info("Auf Speicher „{$disk}“ kopiert".($encrypted ? ' (verschlüsselt).' : '.'));
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

    /** @param  ?list<string>  $only  nur diese Unterordner (null: alles) */
    private function zipUploads(string $target, ?string $source = null, ?array $only = null): ?string
    {
        $source ??= Storage::disk('public')->path('');
        if (! is_dir($source)) {
            return null;
        }

        $zip = new ZipArchive;
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP-Datei konnte nicht angelegt werden.');
        }
        $source = rtrim($source, '/\\');
        foreach ($only ?? [''] as $prefix) {
            $dir = $prefix === '' ? $source : $source.'/'.$prefix;
            if (! is_dir($dir)) {
                continue;
            }
            foreach (File::allFiles($dir, true) as $file) {
                if ($file->getFilename() === '.gitignore') {
                    continue;
                }
                $relative = ltrim(str_replace('\\', '/', $file->getRelativePathname()), '/');
                $zip->addFile($file->getPathname(), $prefix === '' ? $relative : $prefix.'/'.$relative);
            }
        }
        $count = $zip->numFiles;
        $zip->close();

        return $count > 0 ? $target : null;
    }

    /**
     * Sicherungen älter als BACKUP_KEEP_DAYS löschen, von den Deploy-Sicherungen
     * zusätzlich alle bis auf die letzten BACKUP_KEEP_DEPLOY. Extern nur, wenn
     * BACKUP_PRUNE_REMOTE an ist (sonst räumt eine Lifecycle-Regel des Buckets auf).
     */
    private function prune(string $base, bool $remote): int
    {
        $cutoff = now()->subDays(max(1, (int) config('backup.keep_days')))->format('Y-m-d_His');
        $pruned = 0;
        $deployBackups = [];
        foreach (File::directories($base) as $dir) {
            $name = basename($dir);
            $isDeploy = str_ends_with($name, self::DEPLOY_SUFFIX) && preg_match(self::NAME_PATTERN, substr($name, 0, -strlen(self::DEPLOY_SUFFIX))) === 1;
            if (! $isDeploy && preg_match(self::NAME_PATTERN, $name) !== 1) {
                continue;
            }
            if (substr($name, 0, 17) < $cutoff) {
                File::deleteDirectory($dir);
                $pruned++;
            } elseif ($isDeploy) {
                $deployBackups[] = $name;
            }
        }
        rsort($deployBackups);
        foreach (array_slice($deployBackups, max(1, (int) config('backup.keep_deploy', 3))) as $name) {
            File::deleteDirectory($base.'/'.$name);
            $pruned++;
        }

        $disk = config('backup.disk');
        if ($remote && $disk && config('backup.prune_remote', true)) {
            foreach (Storage::disk($disk)->directories('backups') as $dir) {
                $name = basename($dir);
                if (preg_match(self::NAME_PATTERN, $name) && $name < $cutoff) {
                    Storage::disk($disk)->deleteDirectory($dir);
                }
            }
        }

        return $pruned;
    }

    /** Zeitpunkt der neuesten vollständigen täglichen Sicherung (lokal, für app:check-production). */
    public static function latestBackupAt(): ?CarbonInterface
    {
        return self::latestWith('manifest.json');
    }

    /** Zeitpunkt der neuesten Sicherung mit vollständiger externer Kopie (für app:check-production). */
    public static function latestRemoteBackupAt(): ?CarbonInterface
    {
        return self::latestWith(self::REMOTE_MARKER);
    }

    /** Größe der neuesten täglichen Sicherung in Bytes (für die Prüfung des freien Speichers). */
    public static function latestBackupBytes(): ?int
    {
        $at = self::latestBackupAt();
        if (! $at) {
            return null;
        }

        return (int) collect(File::files(rtrim(config('backup.path'), '/').'/'.$at->format('Y-m-d_His')))->sum(fn ($file) => $file->getSize());
    }

    private static function latestWith(string $file): ?CarbonInterface
    {
        $base = rtrim(config('backup.path'), '/');
        if (! is_dir($base)) {
            return null;
        }
        $latest = collect(File::directories($base))
            ->filter(fn ($dir) => is_file($dir.'/'.$file))
            ->map(fn ($dir) => basename($dir))
            ->filter(fn ($name) => preg_match(self::NAME_PATTERN, $name))
            ->sort()->last();

        return $latest ? Carbon::createFromFormat('Y-m-d_His', $latest) : null;
    }
}
