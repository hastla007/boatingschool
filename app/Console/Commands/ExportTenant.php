<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\TenantOffboardingService;
use Illuminate\Console\Command;

/**
 * Datenexport einer Bootsschule auf der Konsole (Audit ARC-V1): derselbe
 * Inhalt wie der Download im Superadmin-Bereich, aber ohne das Zeitlimit von
 * PHP-FPM (request_terminate_timeout 300 s, zählt auch die Übertragung). Für
 * sehr große Schulen bzw. langsame Verbindungen, siehe deploy/README.md.
 * Läuft auf der Owner-Verbindung; die Datei enthält personenbezogene Daten.
 */
class ExportTenant extends Command
{
    protected $signature = 'app:export-tenant
        {tenant : Slug der Schule}
        {path : Zieldatei (JSON), darf noch nicht existieren}';

    protected $description = 'Exportiert alle Daten einer Bootsschule in eine JSON-Datei (wie der Download im Superadmin-Bereich).';

    public function handle(TenantOffboardingService $offboarding): int
    {
        $path = (string) $this->argument('path');
        if (file_exists($path)) {
            $this->error("Die Zieldatei existiert bereits: {$path}");

            return self::FAILURE;
        }

        $previous = config('database.default');
        config(['database.default' => 'pgsql_admin']);
        $handle = null;
        try {
            $tenant = Tenant::where('slug', (string) $this->argument('tenant'))->first();
            if (! $tenant) {
                $this->error('Schule nicht gefunden.');

                return self::FAILURE;
            }

            // Nur für den Betreiber lesbar (personenbezogene Daten).
            $umask = umask(0077);
            $handle = @fopen($path, 'x');
            umask($umask);
            if ($handle === false) {
                $this->error("Zieldatei nicht beschreibbar: {$path}");

                return self::FAILURE;
            }

            AuditLog::record($tenant->id, null, 'superadmin.tenant.export', 'tenant', $tenant->id, null, [
                'members' => TenantUser::where('tenant_id', $tenant->id)->count(), 'via' => 'console',
            ]);
            $members = $offboarding->writeExport($tenant, function (string $chunk) use ($handle) {
                fwrite($handle, $chunk);
            });
            $this->info("{$members} Mitglied(er) exportiert: {$path}");

            return self::SUCCESS;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            config(['database.default' => $previous]);
        }
    }
}
