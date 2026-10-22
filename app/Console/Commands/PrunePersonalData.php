<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

    private function pruneTable(string $configKey, string $dateColumn, string $table, bool $dryRun): void
    {
        $config = config("data_retention.{$configKey}");

        if (! $config['enabled']) {
            $this->line("{$table}: Aufräumen deaktiviert (DATA_RETENTION_".strtoupper($configKey)."_ENABLED -- Frist bewusst zu prüfen, siehe config/data_retention.php).");

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
