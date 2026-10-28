<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bug-Review (Login ohne Index auf lower(email); Scheduler ohne
 * onOneServer): LoginRequest und PasswordResetLinkController lösen die
 * eingegebene Adresse case-insensitiv über whereRaw('lower(email) = ?', ...)
 * auf -- ohne einen passenden Funktionsindex lief das auf einen
 * vollständigen Tabellen-Scan über app_user. Die drei Scheduler-Tasks in
 * routes/console.php hatten zudem keinen Schutz gegen einen zweiten,
 * parallel laufenden Server (onOneServer() nutzt dafür den
 * Datenbank-Cache-Store, der bereits Standard ist).
 */
class SchedulerAndLoginIndexTest extends TestCase
{
    public function test_app_user_has_a_functional_index_on_lower_email(): void
    {
        $index = DB::connection('pgsql_admin')->selectOne(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'app_user' AND indexname = 'idx_app_user_lower_email'"
        );

        $this->assertNotNull($index, 'Erwarteter Funktionsindex idx_app_user_lower_email auf app_user fehlt.');
        $this->assertStringContainsString('lower((email)', $index->indexdef);
    }

    public function test_the_scheduled_commands_run_on_only_one_server(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $commands = ['app:prune-personal-data', 'app:expire-entitlements', 'app:expire-abandoned-exam-sessions'];

        foreach ($commands as $command) {
            $event = collect($schedule->events())->first(fn ($e) => str_contains($e->command, $command));

            $this->assertNotNull($event, "Scheduler-Event für {$command} nicht gefunden.");
            $this->assertTrue($event->onOneServer, "{$command} läuft nicht mit onOneServer().");
        }
    }

    /**
     * Bug-Review (Scheduler läuft ohne withoutOverlapping): onOneServer()
     * schützt nur gegen einen gleichzeitigen zweiten SERVER -- läuft ein
     * Task auf demselben Server länger als sein eigenes Intervall, startet
     * der nächste geplante Lauf ohne withoutOverlapping() trotzdem parallel
     * dazu.
     */
    public function test_the_scheduled_commands_do_not_overlap_with_their_own_next_run(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $commands = ['app:prune-personal-data', 'app:expire-entitlements', 'app:expire-abandoned-exam-sessions'];

        foreach ($commands as $command) {
            $event = collect($schedule->events())->first(fn ($e) => str_contains($e->command, $command));

            $this->assertNotNull($event, "Scheduler-Event für {$command} nicht gefunden.");
            $this->assertTrue($event->withoutOverlapping, "{$command} läuft nicht mit withoutOverlapping().");
        }
    }
}
