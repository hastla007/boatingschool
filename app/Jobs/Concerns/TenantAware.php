<?php

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\SetTenantContext;
use App\Support\TenantContext;

/**
 * Queue-Jobs mit Mandantenkontext: Beim Erzeugen (also beim Dispatch im
 * Request) wird die ID der aktuellen Schule aus dem TenantContext gemerkt;
 * beim Ausführen setzt die Job-Middleware SetTenantContext daraus
 * TenantContext, currentTenant und die Postgres-GUC app.current_tenant_id
 * (RLS) -- genau wie ResolveTenant im Request -- und setzt alles im finally
 * wieder zurück.
 *
 * Wichtig: Jobs mit diesem Trait sollen nur IDs (keine Eloquent-Modelle)
 * als Eigenschaften tragen. SerializesModels lädt Modelle beim
 * Deserialisieren, also BEVOR die Middleware den Mandantenkontext setzt --
 * über die App-Rolle (RLS) fände der Worker sie dann nicht.
 */
trait TenantAware
{
    /** Schule beim Dispatch (null = bewusst ohne Mandant, z. B. Plattform-Mitteilungen). */
    public ?string $tenantId = null;

    /** Aufruf im Konstruktor: ohne Argument die Schule aus dem aktuellen TenantContext. */
    protected function rememberTenant(?string $tenantId = null): void
    {
        $this->tenantId = $tenantId ?? app(TenantContext::class)->id();
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetTenantContext];
    }
}
