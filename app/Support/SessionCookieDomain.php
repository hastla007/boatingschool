<?php

namespace App\Support;

/**
 * Bug-Review R3-1 (Folgefehler), Bug-Review NEU-1 (Regression, Folgefehler
 * von R3-1): reine Ableitungslogik für config/session.php, als eigene
 * Methode testbar ohne Config/Env neu bootstrappen zu müssen.
 *
 * Die R3-1-Fassung leitete hier automatisch ".CENTRAL_DOMAIN" ab (Cookie
 * gilt für die zentrale Domain UND alle ihre Subdomains), um einem bereits
 * eingeloggten Nutzer das Session-Cookie auch auf einer ANDEREN
 * Bootsschul-Subdomain mitzugeben (z. B. beim Öffnen eines Einladungs-
 * Links für eine noch nicht beigetretene Bootsschule). Das brach aber
 * Custom Domains vollständig: ein Tenant mit eigener Domain (siehe
 * ResolveTenant::resolveViaCustomDomain()) liegt NICHT unter
 * CENTRAL_DOMAIN -- der Browser verwirft ein Set-Cookie mit einem
 * Domain-Attribut, das nicht zum aktuell aufgerufenen Host passt, komplett
 * (RFC 6265), die Session ging dadurch bei JEDEM Request verloren (POST
 * /login antwortete serverseitig verifiziert mit 419, da CSRF-Token und
 * Session auseinanderliefen). Dasselbe plattformweite Cookie vergrößerte
 * zusätzlich unnötig die Angriffsfläche: ein XSS auf einer BELIEBIGEN
 * Bootsschul-Subdomain hätte dieselbe Session (inkl. Superadmin, falls
 * dessen Browser dieses Cookie führt) treffen können, Logout/CSRF-Token
 * wirkten faktisch plattformweit statt pro Host.
 *
 * Die eigentliche, ursprüngliche Lücke (RLS-Selbstsichtbarkeit beim Login
 * über eine fremde Subdomain) ist bereits unabhängig vom Cookie-Scope
 * über app.current_user_id in ResolveTenant behoben -- ein frischer Login
 * DIREKT auf der Ziel-Subdomain funktioniert unabhängig von jedem
 * mitgebrachten Cookie. Die automatische Ableitung entfällt deshalb
 * wieder; das Cookie bleibt (wie vor R3-1 und wie Laravel es standardmäßig
 * vorsieht) an genau den Host gebunden, der es gesetzt hat -- das
 * funktioniert für Subdomains UND Custom Domains gleichermaßen korrekt.
 * SESSION_DOMAIN bleibt weiterhin explizit überschreibbar, falls ein
 * Betreiber die (dann bewusst in Kauf genommene) plattformweite
 * Cookie-Freigabe trotzdem will.
 */
class SessionCookieDomain
{
    public static function resolve(?string $sessionDomain): ?string
    {
        if ($sessionDomain !== null && $sessionDomain !== '') {
            return $sessionDomain;
        }

        return null;
    }
}
