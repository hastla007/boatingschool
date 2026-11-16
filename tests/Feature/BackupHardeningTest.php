<?php

namespace Tests\Feature;

use App\Services\BackupVerifier;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Datensicherung: Probe-Wiederherstellung (app:backup:verify). */
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
