<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Löst den Mandanten serverseitig aus Subdomain oder Custom Domain auf
 * (api_spec.md: "Tenant wird serverseitig aus Domain/Subdomain ... aufgelöst").
 * In der lokalen Entwicklungsumgebung kann der Tenant zusätzlich per
 * Query-Parameter erzwungen werden, weil Wildcard-Subdomains hier nicht
 * immer auflösbar sind (Playwright/Tests, Preview-Umgebungen).
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        // Ohne dies könnte bei wiederverwendeten DB-Verbindungen (Octane,
        // PgBouncer im Session-Modus, Tests) app.current_tenant_id aus einem
        // vorherigen Request stehen bleiben und sowohl die Tenant-Auflösung
        // selbst als auch RLS-geschützte Queries dieses Requests verfälschen.
        app(TenantContext::class)->clear();

        $tenant = $this->resolveViaCustomDomain($request)
            ?? $this->resolveViaDevOverride($request)
            ?? $this->resolveViaSubdomain($request);

        if (! $tenant) {
            if ($request->getHost() === config('app.central_domain')) {
                return response()->view('tenant.picker', ['tenants' => Tenant::query()->orderBy('name')->get()]);
            }

            return response()->view('tenant.not-found', [], 404);
        }

        if ($tenant->status === 'closed' || $tenant->status === 'suspended') {
            return response()->view('tenant.suspended', ['tenant' => $tenant], 403);
        }

        app(TenantContext::class)->set($tenant);
        app()->instance('currentTenant', $tenant);
        view()->share('currentTenant', $tenant);
        view()->share('branding', $tenant->branding);

        return $next($request);
    }

    private function resolveViaCustomDomain(Request $request): ?Tenant
    {
        $host = $request->getHost();

        return Tenant::whereHas('branding', fn ($q) => $q->where('custom_domain', $host))->first();
    }

    private function resolveViaDevOverride(Request $request): ?Tenant
    {
        if (! app()->environment('local', 'testing')) {
            return null;
        }

        if ($request->query('as_tenant') === '') {
            $request->session()->forget('dev_tenant_slug');

            return null;
        }

        $slug = $request->query('as_tenant') ?? $request->session()->get('dev_tenant_slug');
        if (! $slug) {
            return null;
        }

        $tenant = Tenant::where('slug', $slug)->first();

        if ($tenant && $request->query('as_tenant')) {
            $request->session()->put('dev_tenant_slug', $slug);
        }

        return $tenant;
    }

    private function resolveViaSubdomain(Request $request): ?Tenant
    {
        $central = config('app.central_domain');
        $host = $request->getHost();

        if ($host === $central || ! str_ends_with($host, '.'.$central)) {
            return null;
        }

        $slug = substr($host, 0, -1 * (strlen($central) + 1));

        return Tenant::where('slug', $slug)->first();
    }
}
