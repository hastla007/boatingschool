<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review B15: entitlement.status wechselte nie automatisch auf
 * "expired", sobald valid_until verstrichen war -- der Enum-Wert existiert
 * zwar, wurde aber nirgends gesetzt. Admin- und Superadmin-Dashboards
 * zählten solche Zeilen dadurch weiter als aktiv, und die
 * Teilnehmerliste zeigte den (veralteten) Status "active" an. Läuft
 * mandantenübergreifend über die Owner-Verbindung, analog zu
 * PrunePersonalData.
 */
class ExpireEntitlements extends Command
{
    protected $signature = 'app:expire-entitlements';

    protected $description = 'Setzt entitlement.status auf "expired" für Zeilen, deren valid_until verstrichen ist.';

    public function handle(): int
    {
        config(['database.default' => 'pgsql_admin']);

        $expired = DB::table('entitlement')
            ->where('status', 'active')
            ->whereNotNull('valid_until')
            ->where('valid_until', '<=', now())
            ->update(['status' => 'expired', 'updated_at' => now()]);

        $this->line("{$expired} Entitlement(s) auf \"expired\" gesetzt.");

        return self::SUCCESS;
    }
}
