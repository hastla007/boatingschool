<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountErasureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DSGVO-Review D3: es gab keinerlei Löschfristen für audit_log, attempt
 * und abgelaufene Sessions. Die konkreten Fristen für audit_log/attempt
 * sind bewusst in config/data_retention.php als deaktivierte Platzhalter
 * hinterlegt, bis sie bewusst geprüft (ggf. mit Rechtsberatung, u. a.
 * wegen möglicher Nachweispflichten für ausgestellte Bootsführerscheine)
 * und per .env aktiviert wurden. Läuft mandantenübergreifend über die
 * Owner-Verbindung, analog zu Migrationen/Seedern/Superadmin.
 */
class PrunePersonalData extends Command
{
    protected $signature = 'app:prune-personal-data {--dry-run : Nur anzeigen, wie viele Zeilen betroffen wären, nichts löschen}';

    protected $description = 'Löscht abgelaufene Sessions sowie (falls aktiviert) Audit-Log- und Lern-Attempt-Zeilen jenseits der konfigurierten Aufbewahrungsfrist.';

    public function handle(): int
    {
        config(['database.default' => 'pgsql_admin']);

        $dryRun = $this->option('dry-run');

        $this->pruneSessions($dryRun);
        $this->pruneTechnicalLeftovers($dryRun);
        $this->pruneUnclaimedAccounts($dryRun);
        $this->pruneTable('audit_log', 'created_at', 'audit_log', $dryRun);
        $this->pruneTable('attempt', 'created_at', 'attempt', $dryRun);

        return self::SUCCESS;
    }

    private function pruneSessions(bool $dryRun): void
    {
        $config = config('data_retention.sessions');

        if (! $config['enabled']) {
            $this->line('Sessions: Aufräumen deaktiviert (DATA_RETENTION_SESSIONS_ENABLED).');

            return;
        }

        $cutoff = now()->subDays($config['days'])->timestamp;
        $query = DB::table('sessions')->where('last_activity', '<', $cutoff);

        if ($dryRun) {
            $this->line("Sessions: {$query->count()} Zeile(n) älter als {$config['days']} Tage würden gelöscht.");

            return;
        }

        $deleted = $query->delete();
        $this->line("Sessions: {$deleted} abgelaufene Zeile(n) gelöscht.");
    }

    /**
     * Abgelaufene Cache-Zeilen (darunter Sperrzähler mit E-Mail und IP aus
     * dem Login-Throttle -- der Datenbank-Cache räumt sie nie selbst ab) und
     * abgelaufene Passwort-Reset-Tokens.
     */
    private function pruneTechnicalLeftovers(bool $dryRun): void
    {
        $cache = Schema::hasTable('cache') ? DB::table('cache')->where('expiration', '<', now()->timestamp) : null;
        $resetMinutes = (int) config('auth.passwords.users.expire', 60);
        $resets = DB::table('password_reset_tokens')->where('created_at', '<', now()->subMinutes($resetMinutes));

        if ($dryRun) {
            $this->line('Cache: '.($cache?->count() ?? 0).' abgelaufene Zeile(n), Passwort-Reset-Tokens: '.$resets->count().' abgelaufen -- würden gelöscht.');

            return;
        }

        $this->line('Cache: '.($cache?->delete() ?? 0).' abgelaufene Zeile(n) gelöscht, Passwort-Reset-Tokens: '.$resets->delete().' gelöscht.');
    }

    /** Nie angenommene Einladungs-Konten und nie bestätigte Registrierungen nach der Frist löschen. */
    private function pruneUnclaimedAccounts(bool $dryRun): void
    {
        $config = config('data_retention.unclaimed_accounts');
        if (! ($config['enabled'] ?? false)) {
            $this->line('Nie aktivierte Konten: Aufräumen deaktiviert (DATA_RETENTION_UNCLAIMED_ACCOUNTS_ENABLED).');

            return;
        }

        $cutoff = now()->subDays((int) $config['days']);
        $users = User::query()->where('is_superadmin', false)->where('created_at', '<', $cutoff)
            ->where(fn ($q) => $q->where('status', 'invited')->orWhereNull('email_verified_at'))
            ->get();

        if ($dryRun) {
            $this->line("Nie aktivierte Konten: {$users->count()} älter als {$config['days']} Tage würden gelöscht.");

            return;
        }

        $erasure = app(AccountErasureService::class);
        foreach ($users as $user) {
            $erasure->deleteAccount($user);
        }
        $this->line("Nie aktivierte Konten: {$users->count()} gelöscht.");
    }

    private function pruneTable(string $configKey, string $dateColumn, string $table, bool $dryRun): void
    {
        $config = config("data_retention.{$configKey}");

        if (! $config['enabled']) {
            $this->line("{$table}: Aufräumen deaktiviert (DATA_RETENTION_".strtoupper($configKey).'_ENABLED -- Frist bewusst zu prüfen, siehe config/data_retention.php).');

            return;
        }

        $cutoff = now()->subMonths($config['months']);
        $query = DB::table($table)->where($dateColumn, '<', $cutoff);

        if ($dryRun) {
            $this->line("{$table}: {$query->count()} Zeile(n) älter als {$config['months']} Monate würden gelöscht.");

            return;
        }

        $deleted = $query->delete();
        $this->line("{$table}: {$deleted} Zeile(n) jenseits der Aufbewahrungsfrist gelöscht.");
    }
}
