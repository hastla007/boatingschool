<?php

namespace Tests\Feature;

use App\Console\Commands\BackupApplication;
use App\Services\BackupVerifier;
use App\Support\BackupEncryption;
use App\Support\ProductionReadiness;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

/** Datensicherung: Verschlüsselung (age/GnuPG), externe Aufbewahrung, fehlendes S3-Paket, Probe-Wiederherstellung. */
class BackupHardeningTest extends TestCase
{
    private string $backupPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupPath = storage_path('framework/testing/backups-'.uniqid());
        config(['backup.path' => $this->backupPath, 'backup.disk' => null]);
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupPath);
        parent::tearDown();
    }

    /** pg_dump wird simuliert; age/gpg laufen echt (falls installiert). */
    private function fakeProcesses(array &$ran = []): void
    {
        Process::fake(function (PendingProcess $process) use (&$ran) {
            $ran[] = $process->command;
            $command = (array) $process->command;
            if (str_contains((string) $command[0], 'pg_dump')) {
                $file = collect($command)->first(fn ($arg) => str_starts_with($arg, '--file='));
                file_put_contents(substr($file, 7), 'PGDMP fake dump');

                return Process::result();
            }
            $real = new SymfonyProcess($command);
            $real->run();

            return Process::result($real->getOutput(), $real->getErrorOutput(), $real->getExitCode());
        });
    }

    private function ageKeypair(): array
    {
        if (! BackupEncryption::available('age') || ! BackupEncryption::available('age-keygen')) {
            $this->markTestSkipped('age ist nicht installiert.');
        }
        $identity = $this->backupPath.'-identity.txt';
        File::ensureDirectoryExists(dirname($identity));
        $gen = new SymfonyProcess(['age-keygen', '-o', $identity]);
        $gen->run();
        preg_match('/age1[0-9a-z]+/', File::get($identity), $m);
        $this->beforeApplicationDestroyed(fn () => File::delete($identity));

        return [$m[0], $identity];
    }

    public function test_encryption_commands_for_age_and_gpg(): void
    {
        config(['backup.encrypt_recipient' => 'age1abc', 'backup.age' => '/opt/age', 'backup.gpg_recipient' => 'backup@example.de', 'backup.gpg' => 'gpg2']);

        $this->assertSame(['/opt/age', '--encrypt', '--recipient', 'age1abc', '--output', '/b/x.age', '/b/x'], BackupEncryption::encryptCommand('age', '/b/x', '/b/x.age'));
        $this->assertSame(['gpg2', '--batch', '--yes', '--trust-model', 'always', '--encrypt', '--recipient', 'backup@example.de', '--output', '/b/x.gpg', '/b/x'],
            BackupEncryption::encryptCommand('gpg', '/b/x', '/b/x.gpg'));
        $this->assertSame('.age', BackupEncryption::extension('age'));
        $this->assertSame('gpg', BackupEncryption::modeOfFile('database.dump.gpg'));
        $this->assertNull(BackupEncryption::modeOfFile('database.dump'));

        // Ohne privaten Schlüssel kein Entschlüsseln.
        $this->assertNull(BackupEncryption::decryptCommand('age', 'a', 'b'));
        config(['backup.age_identity' => '/root/key.txt']);
        $this->assertSame(['/opt/age', '--decrypt', '--identity', '/root/key.txt', '--output', 'b', 'a'], BackupEncryption::decryptCommand('age', 'a', 'b'));
    }

    public function test_age_is_preferred_and_gpg_is_the_fallback_when_age_is_missing(): void
    {
        config(['backup.encrypt_recipient' => null, 'backup.gpg_recipient' => null]);
        $this->assertNull(BackupEncryption::mode());

        config(['backup.encrypt_recipient' => 'age1abc', 'backup.age' => PHP_BINARY, 'backup.gpg_recipient' => 'backup@example.de', 'backup.gpg' => PHP_BINARY]);
        $this->assertSame('age', BackupEncryption::mode());

        config(['backup.age' => '/nicht/vorhanden/age']);
        $this->assertSame('gpg', BackupEncryption::mode());

        config(['backup.gpg_recipient' => null]);
        $this->expectException(RuntimeException::class);
        BackupEncryption::mode();
    }

    public function test_backup_fails_without_writing_plaintext_when_the_encryption_tool_is_missing(): void
    {
        config(['backup.encrypt_recipient' => 'age1abc', 'backup.age' => '/nicht/vorhanden/age', 'backup.gpg_recipient' => null]);
        $ran = [];
        $this->fakeProcesses($ran);

        $this->artisan('app:backup')->assertFailed()->expectsOutputToContain('age ist nicht installiert');

        $this->assertSame([], $ran, 'ohne Verschlüsselungswerkzeug wird gar nicht erst gedumpt');
        $this->assertSame([], File::directories($this->backupPath));
    }

    public function test_backup_is_encrypted_with_age_and_can_be_decrypted_with_the_identity(): void
    {
        [$recipient, $identity] = $this->ageKeypair();
        config(['backup.encrypt_recipient' => $recipient]);
        Storage::disk('public')->put('logos/schule.png', 'png-bytes');
        $this->fakeProcesses();

        $this->artisan('app:backup')->assertSuccessful()->expectsOutputToContain('verschlüsselt mit age');

        $dir = BackupApplication::latestBackupDirectory();
        $this->assertFileExists($dir.'/database.dump.age');
        $this->assertFileExists($dir.'/uploads.zip.age');
        $this->assertFileDoesNotExist($dir.'/database.dump', 'kein Klartext neben der verschlüsselten Datei');
        $this->assertFileDoesNotExist($dir.'/uploads.zip');
        $this->assertStringNotContainsString('PGDMP', File::get($dir.'/database.dump.age'));
        $manifest = json_decode(File::get($dir.'/manifest.json'), true);
        $this->assertSame('age', $manifest['encryption']);
        $this->assertSame(hash_file('sha256', $dir.'/database.dump.age'), $manifest['files']['database.dump.age']['sha256']);

        $decrypt = new SymfonyProcess(['age', '--decrypt', '--identity', $identity, $dir.'/database.dump.age']);
        $decrypt->run();
        $this->assertSame('PGDMP fake dump', $decrypt->getOutput());
    }

    public function test_offsite_copies_are_only_pruned_when_explicitly_enabled(): void
    {
        Storage::fake('backup-extern');
        Storage::disk('backup-extern')->put('backups/2020-01-01_030000/database.dump', 'alt');
        config(['backup.disk' => 'backup-extern', 'filesystems.disks.backup-extern.driver' => 'local']);
        $this->fakeProcesses();

        $this->artisan('app:backup --no-uploads')->assertSuccessful();
        Storage::disk('backup-extern')->assertExists('backups/2020-01-01_030000/database.dump');

        config(['backup.remote_prune' => true]);
        $this->artisan('app:backup --no-uploads')->assertSuccessful();
        Storage::disk('backup-extern')->assertMissing('backups/2020-01-01_030000/database.dump');
    }

    public function test_a_missing_s3_package_keeps_the_local_backup(): void
    {
        if (BackupApplication::s3PackageInstalled()) {
            $this->markTestSkipped('league/flysystem-aws-s3-v3 ist installiert.');
        }
        config(['backup.disk' => 's3', 'filesystems.disks.s3.driver' => 's3']);
        $this->fakeProcesses();

        $this->artisan('app:backup --no-uploads')->assertSuccessful()->expectsOutputToContain('league/flysystem-aws-s3-v3');

        $this->assertNotNull(BackupApplication::latestBackupDirectory());
        $this->assertSame('Paket league/flysystem-aws-s3-v3 fehlt (composer install ausführen).', BackupApplication::offsiteUnavailableReason('s3'));
    }

    public function test_check_production_reports_unencrypted_or_misconfigured_offsite_backups(): void
    {
        $label = fn (string $label) => collect(ProductionReadiness::checks())->firstWhere('label', $label);

        config(['backup.disk' => null, 'backup.encrypt_recipient' => null, 'backup.gpg_recipient' => null]);
        $this->assertNull($label('Sicherung verschlüsselt (BACKUP_ENCRYPT_RECIPIENT)'));

        config(['backup.disk' => 'backup-extern', 'filesystems.disks.backup-extern' => ['driver' => 'local', 'root' => $this->backupPath.'-extern']]);
        $this->assertSame('warn', $label('Sicherung verschlüsselt (BACKUP_ENCRYPT_RECIPIENT)')['status']);
        $this->assertSame('ok', $label('Externer Sicherungsspeicher nutzbar')['status']);

        config(['backup.encrypt_recipient' => 'age1abc', 'backup.age' => PHP_BINARY]);
        $this->assertSame('ok', $label('Sicherung verschlüsselt (BACKUP_ENCRYPT_RECIPIENT)')['status']);
        config(['backup.age' => '/nicht/vorhanden/age']);
        $this->assertSame('fail', $label('Sicherung verschlüsselt (BACKUP_ENCRYPT_RECIPIENT)')['status']);

        if (! BackupApplication::s3PackageInstalled()) {
            config(['backup.disk' => 's3', 'filesystems.disks.s3.driver' => 's3']);
            $this->assertSame('fail', $label('Externer Sicherungsspeicher nutzbar')['status']);
        }
    }

    // --- app:backup:verify ------------------------------------------------

    private function writeBackup(string $name = '2026-01-01_031500', array $files = ['database.dump' => 'PGDMP fake dump']): string
    {
        $dir = $this->backupPath.'/'.$name;
        File::ensureDirectoryExists($dir);
        $meta = [];
        foreach ($files as $file => $content) {
            File::put($dir.'/'.$file, $content);
            $meta[$file] = ['bytes' => strlen($content), 'sha256' => hash('sha256', $content)];
        }
        File::put($dir.'/manifest.json', json_encode(['files' => $meta]));

        return $dir;
    }

    private function fakeVerifier(array $counts, ?array $migrations = null): object
    {
        $live = DB::connection('pgsql_admin')->table('migrations')->pluck('migration')->all();
        $verifier = new class($counts, $migrations ?? $live) extends BackupVerifier
        {
            public array $calls = [];

            public ?string $restoredContent = null;

            public function __construct(private array $counts, private array $migrations) {}

            public function createDatabase(string $name): void
            {
                $this->calls[] = 'create:'.$name;
            }

            public function restore(string $dumpPath, string $name): void
            {
                $this->restoredContent = file_get_contents($dumpPath);
                $this->calls[] = 'restore';
            }

            public function inspect(string $name, array $tables): array
            {
                $this->calls[] = 'inspect';

                return ['migrations' => $this->migrations, 'counts' => array_intersect_key($this->counts, array_flip($tables)) + array_fill_keys($tables, 5)];
            }

            public function dropDatabase(string $name): void
            {
                $this->calls[] = 'drop:'.$name;
            }
        };
        $this->app->instance(BackupVerifier::class, $verifier);

        return $verifier;
    }

    public function test_verify_restores_the_latest_backup_into_a_temporary_database_and_drops_it(): void
    {
        $this->writeBackup('2026-01-01_031500', ['database.dump' => 'alt']);
        $this->writeBackup('2026-02-01_031500');
        $verifier = $this->fakeVerifier([]);

        $this->artisan('app:backup:verify')->assertSuccessful()->expectsOutputToContain('Sicherung lässt sich wiederherstellen.');

        $this->assertSame('PGDMP fake dump', $verifier->restoredContent, 'neueste Sicherung');
        $this->assertSame(['restore', 'inspect'], array_slice($verifier->calls, 1, 2));
        $created = substr($verifier->calls[0], 7);
        $this->assertMatchesRegularExpression('/^[a-z0-9_]+_verify_\d{14}_[a-z0-9]{4}$/', $created);
        $this->assertSame('drop:'.$created, end($verifier->calls));
    }

    public function test_verify_fails_on_empty_tables_or_foreign_migrations_and_still_drops_the_database(): void
    {
        $this->writeBackup();
        $verifier = $this->fakeVerifier(['tenant' => 0]);
        $this->artisan('app:backup:verify')->assertFailed()->expectsOutputToContain('Tabelle tenant ist leer');
        $this->assertStringStartsWith('drop:', end($verifier->calls));

        $verifier = $this->fakeVerifier([], ['2099_01_01_000000_aus_einer_anderen_datenbank']);
        $this->artisan('app:backup:verify')->assertFailed()->expectsOutputToContain('fehlen in der laufenden Datenbank');
        $this->assertStringStartsWith('drop:', end($verifier->calls));
    }

    public function test_verify_rejects_a_backup_with_a_wrong_checksum_before_touching_the_database(): void
    {
        $dir = $this->writeBackup();
        File::put($dir.'/database.dump', 'manipuliert');
        $verifier = $this->fakeVerifier([]);

        $this->artisan('app:backup:verify')->assertFailed()->expectsOutputToContain('Prüfsumme stimmt nicht');
        $this->assertSame([], $verifier->calls);
    }

    public function test_verify_of_an_encrypted_backup_needs_the_private_key(): void
    {
        [$recipient, $identity] = $this->ageKeypair();
        $plain = $this->backupPath.'-plain';
        File::ensureDirectoryExists(dirname($plain));
        File::put($plain, 'PGDMP verschlüsselt');
        (new SymfonyProcess(['age', '-r', $recipient, '-o', $plain.'.age', $plain]))->run();
        $this->writeBackup('2026-03-01_031500', ['database.dump.age' => File::get($plain.'.age')]);
        File::delete([$plain, $plain.'.age']);
        $verifier = $this->fakeVerifier([]);

        // Ohne Schlüssel: nur Prüfsummen.
        $this->artisan('app:backup:verify')->assertSuccessful()->expectsOutputToContain('nur Prüfsummen geprüft');
        $this->assertSame([], $verifier->calls);

        config(['backup.age_identity' => $identity]);
        $this->artisan('app:backup:verify')->assertSuccessful();
        $this->assertSame('PGDMP verschlüsselt', $verifier->restoredContent);
    }

    public function test_verify_explains_the_createdb_requirement(): void
    {
        $canCreate = (bool) DB::connection('pgsql_admin')->selectOne('SELECT rolcreatedb OR rolsuper AS ok FROM pg_roles WHERE rolname = current_user')->ok;
        if ($canCreate) {
            $this->markTestSkipped('Die Owner-Rolle darf hier Datenbanken anlegen.');
        }
        $this->writeBackup();

        $this->artisan('app:backup:verify')->assertFailed()->expectsOutputToContain('CREATEDB');
    }

    public function test_verify_without_any_backup_fails(): void
    {
        $this->artisan('app:backup:verify')->assertFailed()->expectsOutputToContain('Keine vollständige Sicherung');
    }

    public function test_verify_runs_monthly_on_one_server(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'app:backup:verify'));
        $this->assertNotNull($event);
        $this->assertSame('40 4 2 * *', $event->expression);
        $this->assertTrue($event->onOneServer);
    }
}
