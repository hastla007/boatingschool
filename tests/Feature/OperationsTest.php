<?php

namespace Tests\Feature;

use App\Console\Commands\BackupApplication;
use App\Models\TenantBranding;
use App\Support\ProductionReadiness;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
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

        $checks = collect(ProductionReadiness::checks())->keyBy('label');
        foreach (['APP_ENV = production', 'APP_DEBUG aus', 'APP_URL mit https', 'CENTRAL_DOMAIN gesetzt', 'Echter Mailversand', 'Absenderadresse', 'Scheduler (Cron: schedule:run jede Minute)', 'Queue (keine hängenden Jobs)', 'Getrennte Rollen für App und Owner', 'App-Rolle ohne Superuser/BYPASSRLS'] as $label) {
            $this->assertSame('ok', $checks[$label]['status'], $label);
        }
        $this->assertSame('fail', $checks['Datensicherung (app:backup)']['status']);

        // Veraltetes Lebenszeichen: Scheduler gilt als ausgefallen.
        Cache::forever(ProductionReadiness::SCHEDULER_HEARTBEAT_KEY, now()->subMinutes(10)->timestamp);
        $this->assertSame('fail', collect(ProductionReadiness::checks())->firstWhere('label', 'Scheduler (Cron: schedule:run jede Minute)')['status']);
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
}
