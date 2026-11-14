<?php

namespace App\Console\Commands;

use App\Models\LearningGroup;
use App\Services\LearningGroupService;
use Illuminate\Console\Command;

/**
 * Lerngruppe beenden (z. B. Vertrag storniert): alle über die Gruppe
 * erteilten Zugänge werden widerrufen, offene Einladungen verfallen.
 * Andere Zugänge der Mitglieder bleiben. Läuft auf der Owner-Verbindung.
 */
class RevokeLearningGroup extends Command
{
    protected $signature = 'app:learning-group-revoke {group : ID der Lerngruppe}';

    protected $description = 'Beendet eine Lerngruppe und widerruft die über sie erteilten Zugänge.';

    public function handle(LearningGroupService $groups): int
    {
        $previous = config('database.default');
        config(['database.default' => 'pgsql_admin']);
        try {
            $group = preg_match('/^[0-9a-f-]{36}$/i', (string) $this->argument('group')) === 1
                ? LearningGroup::withoutGlobalScopes()->find($this->argument('group')) : null;
            if (! $group) {
                $this->error('Lerngruppe nicht gefunden.');

                return self::FAILURE;
            }
            $groups->revokeGroup($group);
        } finally {
            config(['database.default' => $previous]);
        }

        $this->info("Lerngruppe {$group->id} beendet.");

        return self::SUCCESS;
    }
}
