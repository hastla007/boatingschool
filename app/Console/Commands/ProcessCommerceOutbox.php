<?php

namespace App\Console\Commands;

use App\Commerce\Outbox;
use Illuminate\Console\Command;

/** Sendet fällige Ereignisse der Shop-Anbindung (Ausgang App -> Shop). Läuft minütlich. */
class ProcessCommerceOutbox extends Command
{
    protected $signature = 'app:commerce-outbox';

    protected $description = 'Sendet fällige Ereignisse an den B2B-Shop (Shopkonten neuer Schulen) mit Wiederholung.';

    public function handle(Outbox $outbox): int
    {
        $count = $outbox->process();
        if ($count > 0) {
            $this->line("{$count} Ereignis(se) an den Shop gesendet bzw. versucht.");
        }

        return self::SUCCESS;
    }
}
