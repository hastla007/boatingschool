<?php

namespace App\Http\Middleware\Api;

use App\Api\ApiProblem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Schulkontext der Mobile-API aus X-Tenant-Slug. Der Header ist nur eine
 * Auswahl -- Zugriff gibt es ausschließlich mit aktiver Teilnehmer-
 * Mitgliedschaft (Schulpersonal nutzt die App nicht) in einer laufenden
 * Schule und mit bestätigter E-Mail-Adresse. Danach gilt der Mandanten-
 * kontext samt RLS wie in der Web-Oberfläche.
 */
class ResolveApiTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->header('X-Tenant-Slug');
        if (! is_string($slug) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,119}$/', $slug)) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Eingaben ungültig', null, ['X-Tenant-Slug' => ['Bitte eine Schule auswählen.']]);
        }

        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            throw new ApiProblem(403, 'EMAIL_NOT_VERIFIED', 'E-Mail-Adresse nicht bestätigt', 'Bitte bestätige zuerst deine E-Mail-Adresse.');
        }

        // Fremde oder unbekannte Schule: dieselbe Antwort (keine Auskunft,
        // ob es die Schule gibt).
        $tenant = Tenant::on('pgsql_admin')->where('slug', $slug)->first();
        $membership = $tenant ? TenantUser::on('pgsql_admin')->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('role', 'learner')->first() : null;
        if (! $membership) {
            throw new ApiProblem(403, 'TENANT_MEMBERSHIP_REQUIRED', 'Keine Mitgliedschaft', 'Du bist bei dieser Bootsschule nicht als Teilnehmer eingetragen.');
        }
        if ($membership->status !== 'active') {
            throw new ApiProblem(403, 'TENANT_MEMBERSHIP_SUSPENDED', 'Mitgliedschaft gesperrt', 'Dein Zugang bei dieser Bootsschule ist gesperrt.');
        }
        if (! in_array($tenant->status, ['active', 'trial'], true)) {
            throw new ApiProblem(403, 'TENANT_UNAVAILABLE', 'Bootsschule nicht verfügbar', 'Die Lernplattform dieser Bootsschule ist derzeit nicht verfügbar.');
        }

        $tenant = Tenant::find($tenant->id) ?? $tenant;
        app(TenantContext::class)->set($tenant);
        app()->instance('currentTenant', $tenant);

        return $next($request);
    }
}
