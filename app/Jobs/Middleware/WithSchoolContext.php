<?php

namespace App\Jobs\Middleware;

use App\Models\Tenant;
use App\Support\MailTemplates;
use App\Support\TenantContext;
use Closure;

/**
 * Massen-Mails laufen über die Queue (Audit ARC-2): im Worker gibt es weder
 * Request noch TenantContext. Diese Job-Middleware setzt für die Dauer des
 * Jobs die Schule, aus deren Bereich die Mail kommt (RLS-Kontext, Absender-
 * name, Unterschrift, Antwortadresse über MailSchool), und stellt danach den
 * vorherigen Zustand wieder her. Ohne Schule (Plattform-Mails) läuft der Job
 * bewusst ohne Mandantenkontext. Ist die Schule inzwischen gelöscht, entfällt
 * die Mail.
 */
class WithSchoolContext
{
    public function __construct(public readonly ?string $tenantId) {}

    public function handle(object $job, Closure $next): mixed
    {
        $context = app(TenantContext::class);
        $previous = $context->tenant();

        $tenant = null;
        if ($this->tenantId !== null) {
            $tenant = Tenant::find($this->tenantId);
            if (! $tenant) {
                return null;
            }
        }

        // Langlebiger Worker: Mailvorlagen bei jedem Job frisch laden.
        MailTemplates::flush();

        if ($tenant) {
            $context->set($tenant);
        } elseif ($previous) {
            $context->clear();
        }

        try {
            return $next($job);
        } finally {
            if ($previous) {
                $context->set($previous);
            } elseif ($tenant) {
                $context->clear();
            }
        }
    }
}
