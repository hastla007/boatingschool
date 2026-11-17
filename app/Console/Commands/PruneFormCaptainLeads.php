<?php

namespace App\Console\Commands;

use App\Models\FormCaptainLead;
use App\Models\FormCaptainLeadRequest;
use App\Support\OwnerConnection;
use Illuminate\Console\Command;

/**
 * Aufräumen des Lead-Eingangs (BS-06, docs/integrations/form-captain-leads.md):
 * Idempotenz-Einträge nach config('form_captain.idempotency_days') immer,
 * Leads nach Ablauf von retain_until (180 Tage, an seit der
 * Entscheidung des Inhabers vom 16.11.2026; abschaltbar mit
 * FORM_CAPTAIN_LEAD_RETENTION_ENABLED=false, Frist zur Rechtsprüfung).
 * Täglich über routes/console.php.
 */
class PruneFormCaptainLeads extends Command
{
    protected $signature = 'app:prune-form-captain-leads {--dry-run : Nur zählen, nichts löschen}';

    protected $description = 'Löscht abgelaufene Leads aus Form Captain (falls aktiviert) und alte Idempotenz-Einträge.';

    public function handle(): int
    {
        // Die Lead-Tabellen sind nur für die Owner-Rolle erreichbar (keine Mandanten, kein GRANT an die App-Rolle).
        [$requests, $leads] = OwnerConnection::run(function () {
            $requests = FormCaptainLeadRequest::where('created_at', '<', now()->subDays(max(30, (int) config('form_captain.idempotency_days', 30))));
            $leads = FormCaptainLead::where('retain_until', '<', now());
            $retention = (bool) config('form_captain.retention.enabled', true);
            if ($this->option('dry-run')) {
                return [$requests->count(), $retention ? $leads->count() : 0];
            }

            return [$requests->delete(), $retention ? $leads->delete() : 0];
        });

        $verb = $this->option('dry-run') ? 'würden gelöscht' : 'gelöscht';
        $this->line("Leads {$verb}: {$leads}; Idempotenz-Einträge {$verb}: {$requests}.");
        if (! config('form_captain.retention.enabled', true)) {
            $this->line('Automatische Löschung der Leads ist abgeschaltet (FORM_CAPTAIN_LEAD_RETENTION_ENABLED=false).');
        }

        return self::SUCCESS;
    }
}
