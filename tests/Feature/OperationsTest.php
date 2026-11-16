<?php

namespace Tests\Feature;

use App\Console\Commands\BackupApplication;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Support\BackupEncryption;
use App\Support\ProductionReadiness;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Go-live: Datensicherung, Go-live-Check, Systemstatus, TLS-Prüfendpunkt. */
class OperationsTest extends TestCase
{
    use InteractsWithTenants;

    private string $backupPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupPath = storage_path('framework/testing/backups-'.uniqid());
        config(['backup.path' => $this->backupPath]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupPath);
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function fakePgDump(bool $succeed = true): void
    {
        Process::fake(function (PendingProcess $process) use ($succeed) {
            if (! $succeed) {
                return Process::result('', 'pg_dump: error: connection refused', 1);
            }
            $file = collect($process->command)->first(fn ($arg) => str_starts_with($arg, '--file='));
            file_put_contents(substr($file, 7), 'PGDMP fake dump');

            return Process::result();
        });
    }

    public function test_backup_dumps_the_database_zips_uploads_and_prunes_old_backups(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/schule.png', 'png');
        // Geschützte Medien der privaten Disk gehören dazu, Exporte nicht.
        Storage::fake('local');
        Storage::disk('local')->put('question-media/elwis/bild.png', 'png');
        Storage::disk('local')->put('navigation-charts/karte.png', 'png');
        Storage::disk('local')->put('exports/person.json', '{}');
        Storage::fake('backup-extern');
        config(['backup.disk' => 'backup-extern', 'backup.keep_days' => 14]);
        File::ensureDirectoryExists($this->backupPath.'/2020-01-01_030000');
        $this->fakePgDump();

        $this->artisan('app:backup')->assertSuccessful();

        Process::assertRan(function (PendingProcess $process) {
            $cmd = $process->command;

            return in_array('--format=custom', $cmd, true)
                && in_array('--username='.config('database.connections.pgsql_admin.username'), $cmd, true)
                && ($process->environment['PGPASSWORD'] ?? null) === (string) config('database.connections.pgsql_admin.password');
        });
        $dirs = File::directories($this->backupPath);
        $this->assertCount(1, $dirs, 'alte Sicherung gelöscht');
        $dir = $dirs[0];
        $this->assertFileExists($dir.'/database.dump');
        $this->assertFileExists($dir.'/uploads.zip');
        $manifest = json_decode(File::get($dir.'/manifest.json'), true);
        $this->assertSame(hash_file('sha256', $dir.'/database.dump'), $manifest['files']['database.dump']['sha256']);
        Storage::disk('backup-extern')->assertExists('backups/'.basename($dir).'/uploads.zip');
        Storage::disk('backup-extern')->assertExists('backups/'.basename($dir).'/protected-media.zip');
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($dir.'/protected-media.zip'));
        $entries = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->sort()->values()->all();
        $zip->close();
        $this->assertSame(['navigation-charts/karte.png', 'question-media/elwis/bild.png'], $entries);
        $this->assertNotNull(BackupApplication::latestBackupAt());
    }

    public function test_a_failed_dump_leaves_no_half_backup_behind(): void
    {
        $this->fakePgDump(false);

        $this->artisan('app:backup --no-uploads')->assertFailed();

        $this->assertSame([], File::directories($this->backupPath));
        $this->assertNull(BackupApplication::latestBackupAt());
    }

    public function test_check_production_reports_problems_and_passes_configured_items(): void
    {
        $this->artisan('app:check-production')->assertFailed()->expectsOutputToContain('APP_ENV = production');

        config([
            'app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://lernen.example.de', 'app.central_domain' => 'lernen.example.de',
            'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.mailanbieter.de', 'mail.from.address' => 'noreply@lernen.example.de',
            'queue.default' => 'database',
        ]);
        Cache::forever(ProductionReadiness::SCHEDULER_HEARTBEAT_KEY, now()->timestamp);
        Cache::forever(ProductionReadiness::WORKER_HEARTBEAT_KEY, now()->timestamp);

        $checks = collect(ProductionReadiness::checks())->keyBy('label');
        foreach (['APP_ENV = production', 'APP_DEBUG aus', 'APP_URL mit https', 'CENTRAL_DOMAIN gesetzt', 'Echter Mailversand', 'Absenderadresse', 'Scheduler (Cron: schedule:run jede Minute)', 'Queue-Worker (queue:work)', 'Queue (keine hängenden Jobs)', 'Getrennte Rollen für App und Owner', 'App-Rolle ohne Superuser/BYPASSRLS'] as $label) {
            $this->assertSame('ok', $checks[$label]['status'], $label);
        }
        $this->assertSame('fail', $checks['Datensicherung (app:backup)']['status']);

        // Veraltetes Lebenszeichen: Scheduler gilt als ausgefallen.
        Cache::forever(ProductionReadiness::SCHEDULER_HEARTBEAT_KEY, now()->subMinutes(10)->timestamp);
        $this->assertSame('fail', collect(ProductionReadiness::checks())->firstWhere('label', 'Scheduler (Cron: schedule:run jede Minute)')['status']);
    }

    public function test_check_production_warns_about_a_sync_queue_and_database_only_drivers(): void
    {
        $label = fn (string $label) => collect(ProductionReadiness::checks())->firstWhere('label', $label);

        config(['queue.default' => 'sync']);
        $this->assertSame('warn', $label('Queue-Worker (QUEUE_CONNECTION nicht „sync“)')['status']);
        config(['queue.default' => 'database']);
        $this->assertNull($label('Queue-Worker (QUEUE_CONNECTION nicht „sync“)'));

        // Mehr als 20 aktive Schulen und alles über die Datenbank: Redis empfohlen.
        $existing = $this->onAdmin(fn () => Tenant::whereIn('status', ['active', 'trial'])->count());
        foreach (range(1, max(0, ProductionReadiness::REDIS_RECOMMENDED_FROM_SCHOOLS + 1 - $existing)) as $i) {
            $this->createTestTenant('Redis Schule');
        }
        config(['cache.default' => 'database', 'session.driver' => 'database', 'queue.default' => 'database']);
        $this->assertSame('warn', $label('Cache, Sessions und Queue auf Redis')['status']);
        config(['session.driver' => 'redis']);
        $this->assertNull($label('Cache, Sessions und Queue auf Redis'));
    }

    /** Audit ARC-2: Massen-Mails brauchen den Worker -- Lebenszeichen und Wartezeit werden geprüft. */
    public function test_check_production_reports_a_missing_worker_and_long_waiting_jobs(): void
    {
        config(['queue.default' => 'database']);
        Cache::forget(ProductionReadiness::WORKER_HEARTBEAT_KEY);
        $check = fn (string $label) => collect(ProductionReadiness::checks())->firstWhere('label', $label);
        $this->assertSame('fail', $check('Queue-Worker (queue:work)')['status']);

        // Der Worker schreibt sein Lebenszeichen bei jedem Schleifendurchlauf.
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'arc2-heartbeat-probe', '--stop-when-empty' => true, '--memory' => 4096])->assertSuccessful();
        $this->assertSame('ok', $check('Queue-Worker (queue:work)')['status']);

        $id = DB::connection('pgsql_admin')->table('jobs')->insertGetId([
            'queue' => 'arc2-wait-probe', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subMinutes(11)->timestamp, 'created_at' => now()->subMinutes(11)->timestamp,
        ]);
        try {
            $this->assertSame('fail', $check('Queue (keine hängenden Jobs)')['status']);
            $this->assertStringContainsString('seit 11 Minuten', $check('Queue (keine hängenden Jobs)')['detail']);
        } finally {
            DB::connection('pgsql_admin')->table('jobs')->delete($id);
        }
    }

    public function test_superadmin_sees_the_system_status(): void
    {
        $this->actingAs($this->createSuperAdmin())->get('/superadmin/system')->assertOk()->assertSee('Go-live-Check')->assertSee('Datensicherung (app:backup)');

        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $this->actingAsInTenant($admin, $tenant)->get('/superadmin/system')->assertForbidden();
    }

    public function test_tls_check_only_confirms_our_domains(): void
    {
        $central = config('app.central_domain');
        $active = $this->createTestTenant('TLS Schule');
        $closed = $this->createTestTenant('TLS Geschlossen');
        $this->onAdmin(function () use ($active, $closed) {
            $closed->update(['status' => 'closed']);
            TenantBranding::where('tenant_id', $active->id)->update(['custom_domain' => 'lernen.tls-schule.test']);
        });
        $ask = fn (string $domain) => $this->get('http://127.0.0.1/tls-check?domain='.urlencode($domain));

        $ask($central)->assertOk();
        $ask("{$active->slug}.{$central}")->assertOk();
        $ask('LERNEN.tls-schule.test')->assertOk();
        $ask("{$closed->slug}.{$central}")->assertNotFound();
        $ask("gibt-es-nicht.{$central}")->assertNotFound();
        $ask("a.{$active->slug}.{$central}")->assertNotFound();
        $ask('evil.example.com')->assertNotFound();
        $ask('')->assertNotFound();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('http://127.0.0.1/tls-check?domain='.$central)->assertForbidden();
    }

    // --- Betriebsaudit (OPS-1 … OPS-4, OPS-10) ------------------------------

    private function check(string $label): ?array
    {
        return collect(ProductionReadiness::checks())->firstWhere('label', $label);
    }

    private function zipEntries(string $file): array
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file));
        $entries = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->sort()->values()->all();
        $zip->close();

        return $entries;
    }

    public function test_backup_includes_course_files_and_other_private_folders_but_no_exports(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Storage::disk('local')->put('course-files/handbuch.pdf', 'pdf');
        Storage::disk('local')->put('neue-medien/x.png', 'png');
        Storage::disk('local')->put('api-exports/export.json', '{}');
        Storage::disk('local')->put('imports/fragen.xlsx', 'xlsx');
        $this->fakePgDump();

        $this->artisan('app:backup')->assertSuccessful();

        $dir = File::directories($this->backupPath)[0];
        $this->assertSame(['course-files/handbuch.pdf', 'neue-medien/x.png'], $this->zipEntries($dir.'/protected-media.zip'));
        $this->assertContains('course-files', BackupApplication::privateDirectories());
    }

    public function test_failed_remote_copy_keeps_the_local_backup_and_fails(): void
    {
        $broken = \Mockery::mock(Filesystem::class);
        $broken->shouldReceive('put')->andReturn(false);
        Storage::set('backup-kaputt', $broken);
        config(['backup.disk' => 'backup-kaputt']);
        $this->fakePgDump();

        $this->artisan('app:backup --no-uploads')->assertFailed()->expectsOutputToContain('externe Kopie ist fehlgeschlagen');

        $dirs = File::directories($this->backupPath);
        $this->assertCount(1, $dirs, 'lokale Sicherung bleibt');
        $this->assertFileExists($dirs[0].'/database.dump');
        $this->assertFileExists($dirs[0].'/manifest.json');
        $this->assertFileDoesNotExist($dirs[0].'/'.BackupApplication::REMOTE_MARKER);
        $this->assertNotNull(BackupApplication::latestBackupAt());
        $this->assertNull(BackupApplication::latestRemoteBackupAt());
        $this->assertSame('fail', $this->check('Externe Sicherung erfolgreich')['status']);
    }

    public function test_remote_copies_are_encrypted_and_can_be_decrypted(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/schule.png', random_bytes(3000));
        Storage::fake('backup-extern');
        $keys = BackupEncryption::generateKeyPair();
        config(['backup.disk' => 'backup-extern', 'backup.encryption_public_key' => $keys['public']]);
        $this->fakePgDump();

        $this->artisan('app:backup')->assertSuccessful();

        $dir = File::directories($this->backupPath)[0];
        $name = basename($dir);
        $remote = Storage::disk('backup-extern');
        $remote->assertExists("backups/{$name}/database.dump.enc");
        $remote->assertExists("backups/{$name}/uploads.zip.enc");
        $remote->assertMissing("backups/{$name}/database.dump");
        $remote->assertExists("backups/{$name}/manifest.json");
        $this->assertStringNotContainsString('PGDMP', $remote->get("backups/{$name}/database.dump.enc"));
        $this->assertSame([], glob($dir.'/*.enc'), 'keine verschlüsselten Zwischendateien lokal');
        $this->assertTrue(json_decode(File::get($dir.'/'.BackupApplication::REMOTE_MARKER), true)['encrypted']);
        $this->assertSame('ok', $this->check('Externe Sicherung erfolgreich')['status']);
        $this->assertSame('ok', $this->check('Externe Sicherung verschlüsselt (BACKUP_ENCRYPTION_PUBLIC_KEY)')['status']);

        // Entschlüsseln (Befehl aus deploy/README.md) ergibt genau die lokale Datei.
        $keyFile = $this->backupPath.'/geheim.key';
        File::put($keyFile, $keys['secret']."\n");
        $encrypted = $this->backupPath.'/uploads.zip.enc';
        File::put($encrypted, $remote->get("backups/{$name}/uploads.zip.enc"));
        $this->artisan('app:backup-decrypt', ['input' => $encrypted, '--key-file' => $keyFile])->assertSuccessful();
        $this->assertSame(hash_file('sha256', $dir.'/uploads.zip'), hash_file('sha256', $this->backupPath.'/uploads.zip'));

        // Falscher Schlüssel wird abgelehnt.
        File::put($keyFile, BackupEncryption::generateKeyPair()['secret']);
        $this->artisan('app:backup-decrypt', ['input' => $encrypted, 'output' => $this->backupPath.'/x.zip', '--key-file' => $keyFile])->assertFailed();
        $this->assertFileDoesNotExist($this->backupPath.'/x.zip');
    }

    public function test_encryption_round_trips_large_and_empty_files_and_detects_tampering(): void
    {
        File::ensureDirectoryExists($this->backupPath);
        $keys = BackupEncryption::generateKeyPair();
        foreach (['leer' => '', 'gross' => random_bytes(2 * 1048576 + 123), 'genau' => random_bytes(1048576)] as $name => $data) {
            File::put("{$this->backupPath}/{$name}", $data);
            BackupEncryption::encryptFile("{$this->backupPath}/{$name}", "{$this->backupPath}/{$name}.enc", $keys['public']);
            BackupEncryption::decryptFile("{$this->backupPath}/{$name}.enc", "{$this->backupPath}/{$name}.out", $keys['secret']);
            $this->assertSame($data, File::get("{$this->backupPath}/{$name}.out"), $name);
        }

        $encrypted = File::get("{$this->backupPath}/gross.enc");
        $cases = [
            'abgeschnitten' => substr($encrypted, 0, -1048576),
            'verändert' => substr_replace($encrypted, chr(ord($encrypted[500]) ^ 1), 500, 1),
            'angehängt' => $encrypted.'x',
        ];
        foreach ($cases as $case => $data) {
            File::put("{$this->backupPath}/kaputt.enc", $data);
            try {
                BackupEncryption::decryptFile("{$this->backupPath}/kaputt.enc", "{$this->backupPath}/kaputt.out", $keys['secret']);
                $this->fail("{$case}: nicht erkannt");
            } catch (\RuntimeException) {
                $this->assertFileDoesNotExist("{$this->backupPath}/kaputt.out", $case);
            }
        }
    }

    public function test_remote_pruning_can_be_switched_off_for_bucket_lifecycle_rules(): void
    {
        Storage::fake('backup-extern');
        Storage::disk('backup-extern')->put('backups/2020-01-01_030000/manifest.json', '{}');
        config(['backup.disk' => 'backup-extern', 'backup.prune_remote' => false]);
        $this->fakePgDump();

        $this->artisan('app:backup --no-uploads')->assertSuccessful();
        Storage::disk('backup-extern')->assertExists('backups/2020-01-01_030000/manifest.json');

        config(['backup.prune_remote' => true]);
        $this->travel(1)->seconds();
        $this->artisan('app:backup --no-uploads')->assertSuccessful();
        Storage::disk('backup-extern')->assertMissing('backups/2020-01-01_030000/manifest.json');
    }

    public function test_deploy_backups_stay_local_and_only_the_last_ones_are_kept(): void
    {
        Storage::fake('backup-extern');
        config(['backup.disk' => 'backup-extern', 'backup.keep_deploy' => 2]);
        foreach (['2030-01-01_010000', '2030-01-02_010000', '2030-01-03_010000'] as $old) {
            File::ensureDirectoryExists($this->backupPath.'/'.$old.'-deploy');
        }
        $this->travelTo('2030-01-04 01:00:00');
        $this->fakePgDump();

        $this->artisan('app:backup --deploy --no-uploads')->assertSuccessful();

        $names = collect(File::directories($this->backupPath))->map(fn ($d) => basename($d))->sort()->values()->all();
        $this->assertSame(['2030-01-03_010000-deploy', '2030-01-04_010000-deploy'], $names);
        $this->assertSame([], Storage::disk('backup-extern')->allFiles(), 'Deploy-Sicherung nur lokal');
        // Zählt nicht als tägliche Sicherung.
        $this->assertNull(BackupApplication::latestBackupAt());
    }

    public function test_scheduler_heartbeat_pings_the_external_dead_mans_switch_when_configured(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200), new Response(200)]));
        $stack->push(Middleware::history($history));
        $this->app->instance(ClientInterface::class, new Client(['handler' => $stack]));
        $events = function () {
            $schedule = new Schedule;
            $this->app->instance(Schedule::class, $schedule);
            \Illuminate\Support\Facades\Schedule::clearResolvedInstances();
            require base_path('routes/console.php');

            return collect($schedule->events());
        };

        // Ohne Konfiguration: kein Ping.
        config(['app.ops_heartbeat_url' => null, 'backup.heartbeat_url' => null]);
        $events()->firstWhere('description', 'ops:scheduler-heartbeat')->run($this->app);
        $this->assertSame([], $history);

        config(['app.ops_heartbeat_url' => 'https://hc.example.test/ping/abc', 'backup.heartbeat_url' => 'https://hc.example.test/ping/backup']);
        $all = $events();
        $all->firstWhere('description', 'ops:scheduler-heartbeat')->run($this->app);
        $this->assertSame('https://hc.example.test/ping/abc', (string) $history[0]['request']->getUri());
        $this->assertSame(Cache::get(ProductionReadiness::SCHEDULER_HEARTBEAT_KEY), now()->timestamp);

        // Die tägliche Sicherung pingt nur nach Erfolg.
        $backup = $all->first(fn ($e) => str_contains((string) $e->command, 'app:backup'));
        $backup->exitCode = 1;
        $backup->callAfterCallbacks($this->app);
        $this->assertCount(1, $history);
        $backup->exitCode = 0;
        $backup->callAfterCallbacks($this->app);
        $this->assertSame('https://hc.example.test/ping/backup', (string) $history[1]['request']->getUri());
    }

    public function test_check_production_covers_the_operations_audit_items(): void
    {
        // Totmann-Schalter
        config(['app.ops_heartbeat_url' => null]);
        $this->assertSame('warn', $this->check('Externer Totmann-Schalter (OPS_HEARTBEAT_URL)')['status']);
        config(['app.ops_heartbeat_url' => 'https://hc.example.test/ping/abc']);
        $this->assertSame('ok', $this->check('Externer Totmann-Schalter (OPS_HEARTBEAT_URL)')['status']);

        // TRUSTED_PROXIES
        foreach (['*' => 'warn', '**' => 'warn', '' => 'ok', '10.0.0.1' => 'ok'] as $value => $status) {
            config(['app.trusted_proxies' => $value]);
            $this->assertSame($status, $this->check('TRUSTED_PROXIES ohne „*“')['status'], "TRUSTED_PROXIES={$value}");
        }

        // Alarmempfänger
        $support = $this->onAdmin(fn () => PlatformSetting::query()->value('support_email'));
        $this->onAdmin(fn () => PlatformSetting::query()->update(['support_email' => null]));
        try {
            config(['app.ops_alert_email' => null]);
            $this->assertSame('fail', $this->check('Empfänger für Betriebsalarme')['status']);
            config(['app.ops_alert_email' => 'betrieb@example.de']);
            $this->assertSame('ok', $this->check('Empfänger für Betriebsalarme')['status']);
        } finally {
            $this->onAdmin(fn () => PlatformSetting::query()->update(['support_email' => $support]));
        }

        // SUPERADMIN_PASSWORD
        config(['app.superadmin_password' => 'geheim-geheim']);
        $this->assertSame('warn', $this->check('SUPERADMIN_PASSWORD nicht in der .env')['status']);
        config(['app.superadmin_password' => null]);
        $this->assertSame('ok', $this->check('SUPERADMIN_PASSWORD nicht in der .env')['status']);

        // .env-Rechte
        $envDir = $this->backupPath.'/env';
        File::ensureDirectoryExists($envDir);
        File::put($envDir.'/.env', 'APP_KEY=x');
        $original = $this->app->environmentPath();
        $this->app->useEnvironmentPath($envDir);
        try {
            chmod($envDir.'/.env', 0644);
            $this->assertSame('warn', $this->check('.env nur für den Deploy-Benutzer lesbar')['status']);
            chmod($envDir.'/.env', 0600);
            $this->assertSame('ok', $this->check('.env nur für den Deploy-Benutzer lesbar')['status']);
        } finally {
            $this->app->useEnvironmentPath($original);
        }

        // SESSION_SECURE_COOKIE: bei https Pflicht
        config(['session.secure' => false, 'app.url' => 'https://lernen.example.de']);
        $this->assertSame('fail', $this->check('Sichere Session-Cookies (SESSION_SECURE_COOKIE=true)')['status']);
        config(['app.url' => 'http://localhost']);
        $this->assertSame('warn', $this->check('Sichere Session-Cookies (SESSION_SECURE_COOKIE=true)')['status']);
        config(['session.secure' => true]);
        $this->assertSame('ok', $this->check('Sichere Session-Cookies (SESSION_SECURE_COOKIE=true)')['status']);

        // Externe Sicherung: unverschlüsselt -> Warnung, ohne erfolgreiche Kopie -> Fehler
        config(['backup.disk' => 'backup-extern', 'backup.encryption_public_key' => null]);
        $this->assertSame('warn', $this->check('Externe Sicherung verschlüsselt (BACKUP_ENCRYPTION_PUBLIC_KEY)')['status']);
        $this->assertSame('fail', $this->check('Externe Sicherung erfolgreich')['status']);
        File::ensureDirectoryExists($this->backupPath.'/'.now()->subHours(30)->format('Y-m-d_His'));
        File::put($this->backupPath.'/'.now()->subHours(30)->format('Y-m-d_His').'/'.BackupApplication::REMOTE_MARKER, '{}');
        $this->assertSame('fail', $this->check('Externe Sicherung erfolgreich')['status'], 'zu alt');
        File::ensureDirectoryExists($this->backupPath.'/'.now()->subHour()->format('Y-m-d_His'));
        File::put($this->backupPath.'/'.now()->subHour()->format('Y-m-d_His').'/'.BackupApplication::REMOTE_MARKER, '{}');
        $this->assertSame('ok', $this->check('Externe Sicherung erfolgreich')['status']);
        config(['backup.disk' => null]);
        $this->assertNull($this->check('Externe Sicherung erfolgreich'));

        // Freier Speicher
        $this->assertNotNull($this->check('Freier Speicher für Sicherungen'));
        $gib = 1024 ** 3;
        $this->assertSame('ok', ProductionReadiness::freeSpace(10 * $gib, 2 * $gib)[0]);
        $this->assertSame('warn', ProductionReadiness::freeSpace(5 * $gib, 2 * $gib)[0]);
        $this->assertSame('warn', ProductionReadiness::freeSpace($gib / 2, null)[0]);
        $this->assertSame('fail', ProductionReadiness::freeSpace($gib, 2 * $gib)[0]);

        // Superadmin getrennt (Pfad-Modus ohne eigenen Host)
        config(['app.tenant_url_mode' => 'path', 'app.superadmin_host' => null]);
        $this->assertSame('warn', $this->check('Superadmin getrennt von den Schulen (SUPERADMIN_HOST)')['status']);
        config(['app.superadmin_host' => 'admin.lernen.example.de', 'session.domain' => null]);
        $this->assertSame('ok', $this->check('Superadmin getrennt von den Schulen (SUPERADMIN_HOST)')['status']);
        config(['session.domain' => '.lernen.example.de']);
        $this->assertSame('warn', $this->check('Superadmin getrennt von den Schulen (SUPERADMIN_HOST)')['status']);
        config(['app.superadmin_host' => null, 'session.domain' => null, 'app.tenant_url_mode' => 'subdomain']);
        $this->assertSame('ok', $this->check('Superadmin getrennt von den Schulen (SUPERADMIN_HOST)')['status']);
    }
}
