<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\CanonicalUrl;
use App\Support\Impersonation;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Schulseite von „Als Nutzer ansehen“: Einmal-Link einlösen und die
 * Support-Ansicht beenden. Protokolliert über die Owner-Verbindung (die
 * Einträge gehören zur Schule, entstehen aber teils vor bzw. nach der
 * Anmeldung).
 */
class ImpersonationController extends Controller
{
    public function consume(Request $request, TenantContext $tenantContext, string $token): RedirectResponse
    {
        $data = Cache::pull(Impersonation::cacheKey($token));
        $tenant = $tenantContext->has() ? $tenantContext->tenant() : null;
        abort_unless($data && $tenant && $tenant->id === $data['tenant_id'], 403, 'Der Link ist abgelaufen oder gehört zu einer anderen Bootsschule.');

        $user = User::on('pgsql_admin')->find($data['user_id']);
        $member = TenantUser::on('pgsql_admin')->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $data['user_id'])->where('status', 'active')->exists();
        abort_unless($user && $member && $user->status === 'active' && ! $user->is_superadmin, 403);

        // logoutCurrentDevice() statt logout(): logout() würde das
        // „Angemeldet bleiben“-Token des bisher angemeldeten Kontos neu
        // setzen und es damit auf allen Geräten abmelden.
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::guard('web')->login(User::findOrFail($user->id));
        $request->session()->regenerate();
        $request->session()->put(Impersonation::SESSION_KEY, [
            'impersonator_id' => $data['impersonator_id'],
            'impersonator_name' => $data['impersonator_name'],
            'tenant_id' => $tenant->id,
            'started_at' => now()->timestamp,
        ]);
        $this->audit($tenant->id, $data['impersonator_id'], 'superadmin.impersonate.start', $user->id, isset($data['reason']) ? ['reason' => $data['reason']] : null);

        $role = $user->roleForTenant($tenant);

        return redirect()->route(in_array($role, ['owner', 'admin', 'instructor'], true) ? 'admin.dashboard' : 'dashboard')
            ->with('status', 'Support-Ansicht gestartet: Du siehst die Plattform wie '.$user->name.'. Änderungen sind gesperrt.');
    }

    public function stop(Request $request): Response
    {
        $impersonation = Impersonation::current($request);
        $userId = $request->user()?->id;

        if ($impersonation) {
            $this->audit($impersonation['tenant_id'], $impersonation['impersonator_id'], 'superadmin.impersonate.stop', $userId);
        }

        Impersonation::endSession($request);

        // Zwischenseite statt Redirect: die CSP (form-action 'self') blockiert Redirects nach einem Formular auf eine andere Domain.
        return response()->view('impersonation.ended', [
            'url' => CanonicalUrl::root().($userId && $impersonation ? route('superadmin.users.show', $userId, false) : route('superadmin.dashboard', [], false)),
        ]);
    }

    /** Protokoll auf der Owner-Verbindung, unabhängig vom aktuellen RLS-Kontext. */
    public static function audit(string $tenantId, string $impersonatorId, string $action, ?string $userId, ?array $after = null): void
    {
        AuditLog::$recordedThisRequest++;
        DB::connection('pgsql_admin')->table('audit_log')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'actor_user_id' => $impersonatorId,
            'action' => $action,
            'entity_type' => 'app_user',
            'entity_id' => $userId,
            'after_data' => $after ? json_encode($after) : null,
            // Mit Mikrosekunden: der Query-Builder schreibt now() sonst nur
            // sekundengenau -- mehrere Einträge derselben Sekunde (Start,
            // gesperrte Änderung, Ende) standen dann in zufälliger Reihenfolge
            // im Protokoll.
            'created_at' => now()->format('Y-m-d H:i:s.uP'),
        ]);
    }
}
