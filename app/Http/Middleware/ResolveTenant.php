<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        // Sicherheitsreview M5: app.current_tenant_id wird per SET LOCAL
        // gesetzt (siehe TenantContext), das nur innerhalb der aktuellen
        // Transaktion gilt -- deshalb läuft der gesamte restliche Request
        // (inklusive aller Routen-/Controller-Queries auf dieser
        // Verbindung) hier in einer Transaktion. Am Ende wird automatisch
        // committet bzw. bei einer Exception zurückgerollt und die
        // GUC damit zuverlässig zurückgesetzt, statt bei wiederverwendeten
        // Verbindungen (Octane, PgBouncer im Session-Modus) über das
        // Request-Ende hinaus bestehen zu bleiben.
        return DB::transaction(function () use ($request, $next) {
            app(TenantContext::class)->clear();

            $tenant = $this->resolveViaCustomDomain($request)
                ?? $this->resolveViaDevOverride($request)
                ?? $this->resolveViaSubdomain($request);

            if (! $tenant) {
                // Der Superadmin-Bereich (und Login/Logout, damit ein Superadmin sich
                // überhaupt ohne Bootsschul-Subdomain anmelden kann) ist bewusst
                // mandantenunabhängig und läuft unabhängig vom aufgelösten Host.
                if ($this->isTenantAgnosticRoute($request)) {
                    return $next($request);
                }

                if ($request->getHost() === config('app.central_domain')) {
                    // Sicherheitsreview N5: zeigte bislang auch suspended/closed/
                    // trial-Mandanten öffentlich mit Name und Slug an.
                    return response()->view('tenant.picker', [
                        'tenants' => Tenant::query()->where('status', 'active')->orderBy('name')->get(),
                    ]);
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
        });
    }

    private function isTenantAgnosticRoute(Request $request): bool
    {
        // Sicherheitsreview M7: forgot-password/reset-password berühren wie
        // login/logout keine mandantengebundenen Daten (siehe
        // PasswordResetLinkController, NewPasswordController -- keiner
        // benutzt TenantContext) und müssen plattformweit nach einer
        // E-Mail-Adresse suchen können, bevor überhaupt ein Mandant
        // feststeht. Ohne diese Einstufung griffe die neue app_user-RLS-
        // Policy (siehe Migration 000461) hier fälschlich ein, sobald die
        // Seite über eine echte Bootsschul-Subdomain aufgerufen wird.
        return $request->is('login') || $request->is('logout')
            || $request->is('forgot-password') || $request->is('reset-password') || $request->is('reset-password/*')
            || $request->is('superadmin') || $request->is('superadmin/*');
    }

    private function resolveViaCustomDomain(Request $request): ?Tenant
    {
        $host = $request->getHost();

        // tenant_branding ist RLS-geschützt; an dieser Stelle wurde der
        // Tenant-Kontext gerade erst auf die Nil-UUID geleert (siehe
        // clear() oben), die App-Rolle sähe also nie eine Zeile -- diese
        // eine Mandanten-Auflösung braucht deshalb bewusst die
        // Owner-Verbindung, exakt wie Migrationen/Seeder/Superadmin
        // (Sicherheitsreview N9). Funktional kaputt, kein Spoofing-Risiko:
        // whereHas() fand vorher einfach nie eine Übereinstimmung.
        $tenantId = DB::connection('pgsql_admin')->table('tenant_branding')
            ->where('custom_domain', $host)
            ->value('tenant_id');

        return $tenantId ? Tenant::find($tenantId) : null;
    }

    private function resolveViaDevOverride(Request $request): ?Tenant
    {
        if (! app()->environment('local', 'testing')) {
            return null;
        }

        // Zusätzliche Absicherung (Sicherheitsreview M4): selbst in einer als
        // lokal/Test markierten Umgebung nur aktiv, wenn explizit per
        // DEV_TENANT_OVERRIDE freigeschaltet UND der Request tatsächlich von
        // localhost kommt.
        if (! config('app.dev_tenant_override') || ! in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
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
