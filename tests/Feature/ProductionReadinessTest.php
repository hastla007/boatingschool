<?php

namespace Tests\Feature;

use App\Support\ProductionReadiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Go-live-Check: Punkte aus dem lokalen Probelauf mit produktionsnaher .env. */
class ProductionReadinessTest extends TestCase
{
    private function check(string $label): ?array
    {
        return collect(ProductionReadiness::checks())->firstWhere('label', $label);
    }

    public function test_failover_and_roundrobin_mailers_count_as_real_mail_delivery(): void
    {
        config(['mail.mailers.smtp.host' => 'smtp.mailanbieter.de']);

        // Laravel-Standard „failover“: smtp, im Notfall log -- kein Fehlalarm mehr.
        config(['mail.default' => 'failover', 'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']]]);
        $this->assertSame('ok', $this->check('Echter Mailversand')['status']);
        $this->assertStringContainsString('smtp, log', $this->check('Echter Mailversand')['detail']);

        // Nur log als erster Mailer: verschickt nichts.
        config(['mail.mailers.failover.mailers' => ['log', 'smtp']]);
        $this->assertSame('fail', $this->check('Echter Mailversand')['status']);

        config(['mail.default' => 'roundrobin', 'mail.mailers.roundrobin' => ['transport' => 'roundrobin', 'mailers' => ['ses', 'postmark']]]);
        $this->assertSame('ok', $this->check('Echter Mailversand')['status']);
        config(['mail.mailers.roundrobin.mailers' => ['ses', 'log']]);
        $this->assertSame('fail', $this->check('Echter Mailversand')['status']);

        config(['mail.default' => 'ses-v2', 'mail.mailers.ses-v2' => ['transport' => 'ses-v2']]);
        $this->assertSame('ok', $this->check('Echter Mailversand')['status']);

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1']);
        $this->assertSame('fail', $this->check('Echter Mailversand')['status']);
        config(['mail.default' => 'log']);
        $this->assertSame('fail', $this->check('Echter Mailversand')['status']);
    }

    public function test_frontend_build_must_exist_without_a_leftover_vite_hot_file(): void
    {
        $public = storage_path('framework/testing/public-'.uniqid());
        File::ensureDirectoryExists($public);
        $original = public_path();
        $this->app->usePublicPath($public);
        try {
            $this->assertSame('fail', $this->check('Frontend-Build (npm run build)')['status']);
            $this->assertStringContainsString('npm run build', $this->check('Frontend-Build (npm run build)')['detail']);

            File::ensureDirectoryExists($public.'/build');
            File::put($public.'/build/manifest.json', '{}');
            $this->assertSame('ok', $this->check('Frontend-Build (npm run build)')['status']);

            File::put($public.'/hot', 'http://localhost:5173');
            $this->assertSame('fail', $this->check('Frontend-Build (npm run build)')['status']);
            $this->assertStringContainsString('public/hot', $this->check('Frontend-Build (npm run build)')['detail']);
        } finally {
            $this->app->usePublicPath($original);
            File::deleteDirectory($public);
        }
    }

    public function test_remote_database_needs_verify_full(): void
    {
        $label = 'Verschlüsselte DB-Verbindung (DB_SSLMODE=verify-full)';
        $original = config('database.connections.pgsql');
        try {
            // Lokale Datenbank: Punkt entfällt.
            config(['database.connections.pgsql.host' => '127.0.0.1']);
            $this->assertNull($this->check($label));

            // Nur die Konfiguration ändern, die Verbindung selbst bleibt bestehen.
            config(['database.connections.pgsql.host' => 'db.intern.example.de', 'database.connections.pgsql.sslmode' => 'prefer']);
            $this->assertSame('warn', $this->check($label)['status']);
            $this->assertStringContainsString('sslmode: prefer', $this->check($label)['detail']);
            config(['database.connections.pgsql.sslmode' => 'verify-full']);
            $this->assertSame('ok', $this->check($label)['status']);
        } finally {
            config(['database.connections.pgsql' => $original]);
        }
    }

    public function test_superadmin_two_factor_requirement_is_checked(): void
    {
        $label = 'Zwei-Faktor-Pflicht im Superadmin (SUPERADMIN_REQUIRE_TWO_FACTOR)';
        config(['auth.superadmin_two_factor' => false]);
        $this->assertSame('warn', $this->check($label)['status']);
        config(['auth.superadmin_two_factor' => true]);
        $this->assertSame('ok', $this->check($label)['status']);
    }

    public function test_missing_superadmin_and_storage_link_have_accurate_hints(): void
    {
        // Ohne Superadmin: Befehl zum Anlegen nennen (Änderung nur in der Transaktion).
        $admin = DB::connection('pgsql_admin');
        $admin->beginTransaction();
        try {
            $admin->table('app_user')->where('is_superadmin', true)->update(['is_superadmin' => false]);
            $check = $this->check('Superadmin vorhanden');
            $this->assertSame('fail', $check['status']);
            $this->assertStringContainsString('app:create-superadmin', $check['detail']);
        } finally {
            $admin->rollBack();
        }

        // Kartenausschnitte liegen privat (eigener Punkt), der Storage-Link betrifft sie nicht mehr.
        $detail = $this->storageLinkDetail();
        $this->assertStringContainsString('Logos', $detail);
        $this->assertStringNotContainsString('Kartenausschnitte', $detail);
    }

    private function storageLinkDetail(): string
    {
        $public = storage_path('framework/testing/public-'.uniqid());
        File::ensureDirectoryExists($public);
        $original = public_path();
        $this->app->usePublicPath($public);
        try {
            $check = $this->check('Storage-Link (php artisan storage:link)');
            $this->assertSame('fail', $check['status']);

            return $check['detail'];
        } finally {
            $this->app->usePublicPath($original);
            File::deleteDirectory($public);
        }
    }
}
