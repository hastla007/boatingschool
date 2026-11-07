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
        // Einladungs-Links liegen in einer eigenen Tabelle mit eigener Frist (7 Tage).
        $inviteMinutes = (int) config('auth.passwords.invites.expire', 60 * 24 * 7);
        $invites = Schema::hasTable('account_setup_tokens')
            ? DB::table('account_setup_tokens')->where('created_at', '<', now()->subMinutes($inviteMinutes)) : null;

        if ($dryRun) {
            $this->line('Cache: '.($cache?->count() ?? 0).' abgelaufene Zeile(n), Passwort-Reset-Tokens: '.$resets->count().', Einladungs-Links: '.($invites?->count() ?? 0).' abgelaufen -- würden gelöscht.');

            return;
        }

        $this->line('Cache: '.($cache?->delete() ?? 0).' abgelaufene Zeile(n) gelöscht, Passwort-Reset-Tokens: '.$resets->delete().', Einladungs-Links: '.($invites?->delete() ?? 0).' gelöscht.');
    }

    /** Nie angenommene Einladungs-Konten (nie ein Passwort festgelegt) nach der Frist löschen. */
    private function pruneUnclaimedAccounts(bool $dryRun): void
    {
        $config = config('data_retention.unclaimed_accounts');
        if (! ($config['enabled'] ?? false)) {
            $this->line('Nie aktivierte Konten: Aufräumen deaktiviert (DATA_RETENTION_UNCLAIMED_ACCOUNTS_ENABLED).');

            return;
        }

        $cutoff = now()->subDays((int) $config['days']);
        $users = User::query()->where('is_superadmin', false)->where('created_at', '<', $cutoff)
            // Review-Runde 9: nur nie aktivierte Einladungs-Konten -- ein
            // aktives Konto mit (z. B. nach Adressänderung) unbestätigter
            // E-Mail bleibt; ein vor Kurzem neu verschickter Link zählt als
            // neue Frist.
            ->where('status', 'invited')
            ->whereNotExists(fn ($q) => $q->from('account_setup_tokens')->whereColumn('account_setup_tokens.email', 'app_user.email')->where('account_setup_tokens.created_at', '>=', $cutoff))
            // Nie löschen: Konten mit (bezahltem/zugewiesenem) Kurszugang und
            // Team-Mitglieder einer Schule -- dort entscheidet die Schule.
            ->whereNotExists(fn ($q) => $q->from('entitlement')->whereColumn('entitlement.user_id', 'app_user.id'))
            ->whereNotExists(fn ($q) => $q->from('tenant_user')->whereColumn('tenant_user.user_id', 'app_user.id')->where('tenant_user.role', '!=', 'learner'))
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
