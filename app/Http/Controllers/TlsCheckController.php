<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * „ask“-Endpunkt für automatische Zertifikate (Caddy on-demand TLS, siehe
 * deploy/Caddyfile): Caddy fragt vor dem Ausstellen eines Zertifikats, ob
 * die Domain zu uns gehört -- die zentrale Domain, die Subdomain einer
 * aktiven Bootsschule oder deren eigene Domain. Ohne diese Prüfung könnte
 * jeder per DNS auf den Server zeigende Name Zertifikate anfordern (und
 * die Rate-Limits von Let's Encrypt aufbrauchen).
 *
 * Läuft außerhalb der web-Gruppe (keine Session, kein Mandant) und nur
 * für Anfragen vom Server selbst.
 */
class TlsCheckController extends Controller
{
    public function __invoke(Request $request): Response
    {
        abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);

        $domain = strtolower(trim((string) $request->query('domain', ''), ". \t\n\r\0\x0B"));
        if ($domain === '' || strlen($domain) > 253) {
            return response('unknown', 404);
        }

        return $this->isOurs($domain) ? response('ok', 200) : response('unknown', 404);
    }

    private function isOurs(string $domain): bool
    {
        $central = strtolower((string) config('app.central_domain'));
        if ($domain === $central || $domain === 'www.'.$central) {
            return true;
        }

        // Eigene Domains und Schul-Subdomains: Owner-Verbindung (kein Mandanten-Kontext, RLS).
        $db = DB::connection('pgsql_admin');
        if (str_ends_with($domain, '.'.$central)) {
            $slug = substr($domain, 0, -strlen('.'.$central));

            return ! str_contains($slug, '.') && $db->table('tenant')->whereRaw('lower(slug) = ?', [$slug])->whereIn('status', ['active', 'trial'])->exists();
        }

        return $db->table('tenant_branding')
            ->join('tenant', 'tenant.id', '=', 'tenant_branding.tenant_id')
            ->whereRaw('lower(tenant_branding.custom_domain) = ?', [$domain])
            // Wie ResolveTenant: auch Schulen in der Testphase sind erreichbar.
            ->whereIn('tenant.status', ['active', 'trial'])
            ->exists();
    }
}
