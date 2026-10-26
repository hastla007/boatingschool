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
        // Bug-Review R3-7 (Folgefehler Sicherheitsreview M5): vormals lief
        // der GESAMTE restliche Request (inklusive aller Routen-/
        // Controller-Queries) in einer Transaktion, nur damit SET LOCAL
        // app.current_tenant_id/app.current_user_id (siehe TenantContext)
        // am Transaktionsende zuverlässig zurückgesetzt wird. Das hielt
        // aber jedes im Request erworbene DB-Lock bis zum Request-Ende --
        // auch während des synchronen SMTP-Versands über BestEffortMail
        // (aktuell implementiert keine Notification ShouldQueue, siehe
        // README.md) -- und täuschte eine Atomarität des gesamten Requests
        // vor, die es nie gab: die meisten Fehlerpfade fangen ihre Exception
        // bereits selbst (BestEffortMail, ProfileController::confirmEmail()
        // u. a.), die Transaktion committete also ohnehin normal weiter,
        // teilweise geschriebene Daten blieben trotzdem bestehen. Operationen,
        // die wirklich mehrere Schreibvorgänge atomar brauchen (z. B.
        // CouponRedemptionService::redeem()), kapseln sich dafür bereits
        // selbst in einen eigenen DB::transaction()-Block.
        //
        // Die GUCs werden jetzt per SET (gilt für die gesamte Verbindungs-
        // SESSION, siehe TenantContext) gesetzt und per app()->terminating()
        // -Callback am Ende JEDES Requests explizit per RESET zurückgesetzt
        // -- unabhängig vom weiteren Transaktionsstatus. Das erhält die
        // Sicherheitsreview-M5-Garantie (GUC bleibt nie über das Request-
        // Ende hinaus auf einer wiederverwendeten Verbindung bestehen, z. B.
        // PgBouncer im Session-Modus), ohne den ganzen Request in eine
        // Transaktion zu zwingen. Die Verbindung wird VOR etwaigen späteren
        // Verbindungswechseln (UseAdminDatabaseConnection) festgehalten,
        // damit der Reset garantiert dieselbe Verbindung trifft, auf der die
        // GUCs gesetzt wurden.
        $connection = DB::connection();
        app()->terminating(function () use ($connection) {
            $connection->statement('RESET app.current_tenant_id');
            $connection->statement('RESET app.current_user_id');
        });

        app(TenantContext::class)->clear();
        $this->setCurrentUserIdForRls($request);

        $tenant = $this->resolveViaCustomDomain($request)
            ?? $this->resolveViaDevOverride($request)
            ?? $this->resolveViaSubdomain($request);

        // Bug-Review R3-1 (Folgefehler): diese Prüfung griff bisher nur,
        // wenn GAR KEIN Tenant aufgelöst werden konnte (zentrale Domain) --
        // auf einer echten Bootsschul-Subdomain wurde stattdessen weiter
        // unten der REALE Tenant-Kontext gesetzt, obwohl der Kommentar dort
        // ("läuft unabhängig vom aufgelösten Host") etwas anderes verspricht.
        // app_user_select_visibility (Migration 000461) macht eine Zeile
        // aber nur sichtbar, wenn kein echter Tenant-Kontext gesetzt ist
        // ODER der Nutzer bereits Mitglied DIESER Bootsschule ist -- ein
        // Login mit korrektem Passwort schlug deshalb fehl
        // ("Diese Zugangsdaten wurden nicht gefunden"), sobald jemand
        // /login direkt über die Subdomain einer Bootsschule aufrief, bei
        // der er (noch) kein Mitglied ist (z. B. beim allerersten Login
        // direkt über einen Einladungs-Link). Branding bleibt trotzdem
        // erhalten (Logo/Farben der gerade aufgerufenen Bootsschule), nur
        // die RLS-GUC bleibt auf der Nil-UUID (siehe TenantContext::clear()
        // oben), unter der app_user_select_visibility plattformweit
        // sichtbar lässt.
        if ($this->isTenantAgnosticRoute($request)) {
            if ($tenant) {
                app()->instance('currentTenant', $tenant);
                view()->share('currentTenant', $tenant);
                view()->share('branding', $tenant->branding);
            }

            return $next($request);
        }

        if (! $tenant) {
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

    /**
     * Sicherheitsreview R3-1: app_user_select_visibility (Migration 000461)
     * macht eine Zeile nur sichtbar, wenn kein echter Tenant-Kontext gesetzt
     * ist ODER der Nutzer bereits tenant_user der aktuell aufgelösten
     * Bootsschule ist -- ein bereits eingeloggter Nutzer, der noch KEIN
     * Mitglied dieser Bootsschule ist (z. B. beim Aufruf eines
     * Einladungs-Links auf deren Subdomain, siehe InvitationAcceptController),
     * verschwindet dadurch vor sich selbst: EnsureAccountIsActive/
     * Auth::user() lädt ihn über genau diese RLS-beschränkte Verbindung neu,
     * sobald der echte Tenant-Kontext steht (läuft nach ResolveTenant),
     * findet die eigene Zeile nicht mehr und behandelt ihn wie nicht
     * eingeloggt -- eine Einladungsannahme für ein bestehendes Konto wäre
     * damit faktisch nie möglich.
     *
     * app.current_user_id wird deshalb zusätzlich aus dem RAW
     * Session-Wert gesetzt (derselbe Schlüssel, den Laravels SessionGuard
     * selbst für den Login-Status verwendet, siehe SessionGuard::getName())
     * -- die Session ist signiert/verschlüsselt und damit vom Client nicht
     * fälschbar, ihr Inhalt muss für diese reine Sichtbarkeits-Freigabe
     * noch nicht per DB-Query bestätigt sein (das übernimmt die
     * anschließende Auth-Resolution ohnehin). Die ergänzte RLS-Policy
     * (Migration 000468) lässt eine Zeile zusätzlich sichtbar, wenn ihre
     * id mit dieser GUC übereinstimmt -- ein Nutzer sieht damit immer sich
     * selbst, unabhängig von jeder Mitgliedschaft.
     *
     * Bug-Review R4-D (Folgefehler R3-7): der Rückweg ohne gültige
     * Session-Nutzer-ID kehrte hier bisher einfach zurück, OHNE die GUC
     * zu bereinigen -- der app()->terminating()-Callback (siehe handle())
     * setzt sie zwar am ENDE jedes Requests zurück, das ist aber die
     * einzige Absicherung, die rein vom PHP-Code abhängt (ein Fataler
     * Fehler, der den Callback nie ausführen lässt, oder ein entgegen der
     * README-Vorgabe im Transaction- statt Session-Modus betriebener
     * Connection-Pooler, könnte diesen Reset überspringen). Diese Methode
     * setzt die GUC deshalb jetzt AUCH am REQUEST-START explizit und
     * unabhängig vom Ergebnis des vorherigen Requests -- dieselbe
     * Verteidigungslinie, die TenantContext::clear() für
     * app.current_tenant_id bereits am Anfang von handle() leistet.
     */
    private function setCurrentUserIdForRls(Request $request): void
    {
        $userId = $request->hasSession()
            ? $request->session()->get('login_web_'.sha1(\Illuminate\Auth\SessionGuard::class))
            : null;

        if (! $userId || ! preg_match('/^[0-9a-f-]{36}$/i', $userId)) {
            DB::statement('RESET app.current_user_id');

            return;
        }

        // Bug-Review R3-7: SET statt SET LOCAL, siehe Kommentar in handle() --
        // der Reset am Request-Ende läuft über den app()->terminating()
        // -Callback statt über eine Transaktionsgrenze, UND (siehe oben)
        // zusätzlich am Request-Start hier.
        DB::statement("SET app.current_user_id = '{$userId}'");
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
