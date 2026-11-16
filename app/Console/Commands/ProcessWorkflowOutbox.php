<?php

namespace App\Console\Commands;

use App\Workflow\WorkflowDispatcher;
use Illuminate\Console\Command;

/**
 * Stellt fällige Workflow-Ereignisse an den BoatingSchool Workflow Connector
 * zu (docs/workflow/README.md). Läuft minütlich; --requeue reiht ein
 * fehlgeschlagenes Ereignis mit derselben Event-ID erneut ein (Outbox-Replay).
 */
class ProcessWorkflowOutbox extends Command
{
    protected $signature = 'app:workflow-outbox {--requeue= : Event-ID eines fehlgeschlagenen Ereignisses erneut einreihen} {--prune : Erledigte Ereignisse nach der Frist löschen}';

    protected $description = 'Stellt fällige Workflow-Ereignisse an den Workflow Connector zu (mit Wiederholung).';

    public function handle(WorkflowDispatcher $dispatcher): int
    {
        if ($id = (string) $this->option('requeue')) {
            if (! WorkflowDispatcher::requeue($id)) {
                $this->error('Kein fehlgeschlagenes Ereignis mit dieser ID.');

                return self::FAILURE;
            }
            $this->line('Ereignis erneut eingereiht.');
        }
        if ($this->option('prune')) {
            $this->line(WorkflowDispatcher::prune().' erledigte(s) Ereignis(se) gelöscht.');
        }
        $count = $dispatcher->process();
        if ($count > 0) {
            $this->line("{$count} Ereignis(se) zugestellt bzw. versucht.");
        }

        return self::SUCCESS;
    }
}
