<?php

namespace App\Providers;

use App\Http\Controllers\Workflow\WorkflowController;
use App\Http\Middleware\Workflow\VerifyWorkflowSignature;
use App\Workflow\ActionRegistry;
use App\Workflow\WorkflowDispatcher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Workflow-Vertrag (docs/workflow/README.md): Endpunkte, Befehlsliste und
 * Zustellung des Ausgangs an einer Stelle, getrennt von der Commerce-API.
 *
 * Endpunkte unter /api/integrations/v1/workflow -- ohne web-/api-Gruppe
 * (keine Session, kein Mandant aus der URL) und ohne admin-db: Befehle
 * laufen auf der App-Verbindung mit dem RLS-Kontext der serverseitig
 * ermittelten Schule (CommandHandler).
 */
class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActionRegistry::class);
    }

    public function boot(): void
    {
        Route::aliasMiddleware('workflow.signed', VerifyWorkflowSignature::class);

        if (! $this->app->routesAreCached()) {
            Route::prefix('api/integrations/v1/workflow')->group(function () {
                Route::post('/handshake', [WorkflowController::class, 'handshake'])->middleware('workflow.signed:handshake')->name('workflow.handshake');
                Route::post('/commands', [WorkflowController::class, 'command'])->middleware('workflow.signed:command')->name('workflow.command');
            });
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Durable Zustellung: alles, was die Zustellung direkt nach dem
            // Commit nicht geschafft hat, spätestens hier, mit Wiederholung.
            $schedule->command('app:workflow-outbox')->everyMinute()->onOneServer()->withoutOverlapping(5);
            $schedule->call(fn () => WorkflowDispatcher::prune())->name('workflow-outbox-prune')->dailyAt('04:35')->timezone('Europe/Berlin')->onOneServer();
        });
    }
}
