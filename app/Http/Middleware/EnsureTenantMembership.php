<?php

namespace App\Http\Middleware;

use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verhindert, dass eine bei Bootsschule A angemeldete Session Lerninhalte
 * von Bootsschule B sieht, nur weil die zentrale Dev-Domain den Tenant per
 * Query-Parameter wechselt. Produktiv trennt bereits die subdomain-gebundene
 * Session dies; dies ist die zusätzliche serverseitige Prüfung.
 */
class EnsureTenantMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(TenantContext::class)->tenant();
        $user = $request->user();

        // Offene Einladung in diese Schule (z. B. direkt nach der
        // E-Mail-Bestätigung): zur Annahme weiterleiten statt „Kein Zugang“.
        $hasMembershipRow = $user && $tenant && TenantUser::on('pgsql_admin')->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists();
        // Nur ohne bestehende (z. B. gesperrte) Mitgliedschaft -- sonst würde
        // die Einladung stillschweigend verbraucht, ohne etwas zu ändern.
        if ($user && $tenant && ! $hasMembershipRow && $request->isMethod('GET') && $user->hasVerifiedEmail()) {
            $invitation = TenantInvitation::on('pgsql_admin')->withoutGlobalScopes()->where('tenant_id', $tenant->id)
                ->whereRaw('lower(email) = ?', [mb_strtolower($user->email)])->whereNull('accepted_at')
                ->where('created_at', '>=', now()->subDays(7))->latest('created_at')->first();
            if ($invitation) {
                return redirect()->to(URL::temporarySignedRoute('invitations.accept', now()->addMinutes(10), [
                    'invitation' => $invitation->id, 'hash' => sha1($invitation->email),
                ]));
            }
        }

        if (! $user || ! $tenant || ! $user->roleForTenant($tenant)) {
            // Gesperrte oder beendete Mitgliedschaft: zur Seite „Meine Daten“
            // (Export und Kontolöschung bleiben möglich).
            $formerMember = $hasMembershipRow;
            if ($formerMember && $request->isMethod('GET') && ! $request->expectsJson()) {
                return redirect()->route('profile.data-rights')
                    ->with('status', 'Dein Zugang zu dieser Bootsschule ist gesperrt. Deine Daten kannst du hier herunterladen oder dein Konto löschen.');
            }
            abort(403, 'Kein Zugang zu dieser Bootsschule.');
        }

        return $next($request);
    }
}
