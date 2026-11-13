<?php

namespace App\Jobs\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job-Middleware zu App\Jobs\Concerns\TenantAware: setzt vor handle() den
 * Mandantenkontext so, wie ResolveTenant es im Request tut
 * (TenantContext::set() -> SET app.current_tenant_id, app.current_user_id
 * zurückgesetzt, currentTenant gebunden), und stellt im finally den
 * vorherigen Zustand wieder her.
 *
 * Wiederherstellen statt nur Leeren: Mit QUEUE_CONNECTION=sync (Tests,
 * lokale Entwicklung) läuft der Job mitten im Request -- der Request muss
 * danach mit seinem eigenen Mandanten und seiner eigenen
 * app.current_user_id weiterarbeiten. Im Worker ist der vorherige Zustand
 * leer, dort bleibt die Verbindung also auf der Nil-UUID (TenantContext::
 * clear()) und ohne app.current_user_id -- der nächste Job auf derselben
 * Verbindung erbt nichts.
 */
class SetTenantContext
{
    public function handle(object $job, Closure $next): mixed
    {
        $context = app(TenantContext::class);
        $previousTenant = $context->tenant();
        $previousBound = app()->bound('currentTenant') ? app('currentTenant') : null;
        $previousUserId = $this->currentUserId();

        try {
            // Ein Job läuft nie im Namen eines angemeldeten Kontos.
            DB::statement('RESET app.current_user_id');

            $tenantId = $job->tenantId ?? null;
            if ($tenantId === null) {
                $context->clear();
                app()->forgetInstance('currentTenant');

                return $next($job);
            }

            $tenant = Tenant::find($tenantId);
            if (! $tenant) {
                // Schule inzwischen gelöscht: nichts mehr zu tun (kein Fehler,
                // ein erneuter Versuch fände sie auch nicht).
                Log::warning('Queue-Job ohne Schule übersprungen', ['job' => $job::class, 'tenant_id' => $tenantId]);
                $context->clear();

                return null;
            }

            $context->set($tenant);
            app()->instance('currentTenant', $tenant);

            return $next($job);
        } finally {
            if ($previousTenant) {
                $context->set($previousTenant);
            } else {
                $context->clear();
            }
            if ($previousBound) {
                app()->instance('currentTenant', $previousBound);
            } else {
                app()->forgetInstance('currentTenant');
            }
            if ($previousUserId !== null) {
                DB::statement("SET app.current_user_id = '{$previousUserId}'");
            } else {
                DB::statement('RESET app.current_user_id');
            }
        }
    }

    /** Aktuelle app.current_user_id (nur eine gültige UUID, sonst null). */
    private function currentUserId(): ?string
    {
        $value = DB::selectOne("SELECT current_setting('app.current_user_id', true) AS v")?->v;

        return is_string($value) && preg_match('/^[0-9a-f-]{36}$/i', $value) ? $value : null;
    }
}
