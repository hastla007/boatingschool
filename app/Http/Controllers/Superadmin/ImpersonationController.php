<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\CanonicalUrl;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Startet „Als Nutzer ansehen“: erzeugt einen Einmal-Link (60 Sekunden
 * gültig) auf die Adresse der Bootsschule, wo die Anmeldung in deren
 * eigener Sitzung passiert (Cookies gelten je Schule). Weiter geht es über
 * eine Zwischenseite mit Link statt eines Redirects: die CSP
 * (form-action 'self') blockiert Redirects nach einem Formular auf andere
 * Domains (Subdomain-Modus, eigene Domain).
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, User $user, Tenant $tenant): View|RedirectResponse
    {
        $error = match (true) {
            $user->id === $request->user()->id => 'Das eigene Konto lässt sich nicht ansehen.',
            (bool) $user->is_superadmin => 'Superadmin-Konten lassen sich nicht ansehen.',
            $user->status !== 'active' => 'Nur aktive Konten lassen sich ansehen.',
            $tenant->status !== 'active' => 'Die Bootsschule ist nicht aktiv.',
            ! TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('status', 'active')->exists() => 'Die Person ist bei dieser Bootsschule nicht (mehr) aktiv.',
            default => null,
        };
        if ($error) {
            return back()->withErrors(['impersonate' => $error]);
        }

        $token = Str::random(48);
        Cache::put(Impersonation::cacheKey($token), [
            'impersonator_id' => $request->user()->id,
            'impersonator_name' => $request->user()->name,
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ], now()->addSeconds(Impersonation::TOKEN_TTL_SECONDS));

        return view('superadmin.users.impersonate', [
            'url' => CanonicalUrl::root($tenant).route('impersonate.consume', ['token' => $token], false),
            'user' => $user,
            'tenant' => $tenant,
        ]);
    }
}
