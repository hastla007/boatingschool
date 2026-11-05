<?php

namespace App\Console\Commands;

use App\Support\ProductionReadiness;
use Illuminate\Console\Command;

/** Go-live-Check: listet offene Punkte für den Produktivbetrieb (Exit-Code 1 bei Fehlern). */
class CheckProduction extends Command
{
    protected $signature = 'app:check-production';

    protected $description = 'Prüft Konfiguration und Betrieb auf Lücken vor bzw. nach dem Go-live.';

    public function handle(): int
    {
        $checks = ProductionReadiness::checks();
        $symbol = ['ok' => '<fg=green>OK  </>', 'warn' => '<fg=yellow>WARN</>', 'fail' => '<fg=red>FEHL</>'];

        foreach (collect($checks)->groupBy('group') as $group => $items) {
            $this->newLine();
            $this->line("<options=bold>{$group}</>");
            foreach ($items as $c) {
                $this->line('  '.$symbol[$c['status']].'  '.$c['label'].($c['detail'] !== '' ? "  <fg=gray>({$c['detail']})</>" : ''));
            }
        }

        $counts = collect($checks)->countBy('status');
        $this->newLine();
        $this->line(sprintf('Ergebnis: %d OK, %d Warnungen, %d Fehler', $counts['ok'] ?? 0, $counts['warn'] ?? 0, $counts['fail'] ?? 0));

        return ($counts['fail'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
