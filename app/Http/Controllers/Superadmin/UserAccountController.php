<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\TwoFactorResetBySupport;
use App\Services\AccountErasureService;
use App\Support\BestEffortMail;
use App\Support\StatusLabels;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Support-Werkzeuge des Superadmins für ein Konto: Zwei-Faktor-Anmeldung
 * zurücksetzen, Mitgliedschaften bearbeiten oder entfernen, Konto löschen.
 * Alles wird protokolliert.
 */
class UserAccountController extends Controller
{
    private const MEMBERSHIP_STATUSES = ['active', 'suspended', 'closed'];

    public function resetTwoFactor(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->two_factor_secret || $user->two_factor_confirmed_at, 409);

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        AuditLog::record(null, $request->user()->id, 'superadmin.user.two_factor_reset', 'app_user', $user->id, null, null);
        // Kontowiederherstellung: alle bestehenden Anmeldungen beenden.
        $user->endOtherSessions();
        BestEffortMail::send(fn () => $user->notify(new TwoFactorResetBySupport), '2FA-Reset '.$user->id);

        return back()->with('status', 'Zwei-Faktor-Anmeldung zurückgesetzt. Die Person wurde per E-Mail informiert.');
    }

    public function updateMembership(Request $request, User $user, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(array_keys(StatusLabels::tenantRoleOptions()))],
            'status' => ['required', Rule::in(self::MEMBERSHIP_STATUSES)],
        ]);
        $membership = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->firstOrFail();

        $losesOwner = $membership->role === 'owner' && $membership->status === 'active'
            && ($validated['role'] !== 'owner' || $validated['status'] !== 'active');
        if ($losesOwner && $this->isSoleOwner($membership)) {
            return back()->withErrors(['membership' => "{$tenant->name} hätte danach keinen Inhaber mehr. Bitte zuerst eine andere Person zum Inhaber machen."]);
        }

        $before = $membership->only(['role', 'status']);
        $membership->update($validated);
        AuditLog::record($tenant->id, $request->user()->id, 'superadmin.user.membership_update', 'tenant_user', $user->id, $before, $validated);

        // Gesperrt oder entfernt: laufende Sitzungen beenden (wie beim Sperren des Kontos).
        if ($before['status'] === 'active' && $validated['status'] !== 'active') {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        return back()->with('status', "Mitgliedschaft bei {$tenant->name} gespeichert.");
    }

    public function destroyMembership(Request $request, User $user, Tenant $tenant, AccountErasureService $erasure): RedirectResponse
    {
        $membership = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->firstOrFail();
        if ($membership->role === 'owner' && $membership->status === 'active' && $this->isSoleOwner($membership)) {
            return back()->withErrors(['membership' => "{$tenant->name} hätte danach keinen Inhaber mehr. Bitte zuerst eine andere Person zum Inhaber machen."]);
        }

        $lastMembership = TenantUser::withoutGlobalScopes()->where('user_id', $user->id)->count() === 1;
        AuditLog::record($tenant->id, $request->user()->id, 'superadmin.user.membership_delete', 'tenant_user', null, null, ['account_deleted' => $lastMembership]);
        $erasure->leaveTenant($user, $tenant, forgetOtherSchoolsInvitations: false); // Review-Runde 12: nur diese Schule

        return $lastMembership
            ? redirect()->route('superadmin.users.index')->with('status', 'Mitgliedschaft entfernt. Da es die letzte war, wurde das Konto gelöscht.')
            : back()->with('status', "Mitgliedschaft bei {$tenant->name} samt Lerndaten entfernt.");
    }

    public function destroy(Request $request, User $user, AccountErasureService $erasure): RedirectResponse
    {
        $request->validate(['confirm_email' => ['required', 'string', Rule::in([$user->email])]], [
            'confirm_email.in' => 'Zur Bestätigung bitte genau die E-Mail-Adresse des Kontos eintippen.',
        ]);

        if ($user->id === $request->user()->id) {
            return back()->withErrors(['confirm_email' => 'Das eigene Konto lässt sich hier nicht löschen.']);
        }
        if ($user->is_superadmin) {
            return back()->withErrors(['confirm_email' => 'Bitte zuerst die Superadmin-Rechte entziehen.']);
        }
        $soleOwnerOf = TenantUser::withoutGlobalScopes()->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active')->with('tenant')->get()
            ->filter(fn (TenantUser $m) => $this->isSoleOwner($m))->map(fn ($m) => $m->tenant?->name)->filter();
        if ($soleOwnerOf->isNotEmpty()) {
            return back()->withErrors(['confirm_email' => 'Einziger Inhaber von '.$soleOwnerOf->implode(', ').'. Bitte zuerst eine andere Person zum Inhaber machen.']);
        }

        $erasure->deleteAccount($user);
        // Nur Kennzahlen, keine personenbezogenen Daten.
        AuditLog::record(null, $request->user()->id, 'superadmin.user.delete', 'app_user', null, null, ['deleted' => true]);

        return redirect()->route('superadmin.users.index')->with('status', 'Konto und alle zugehörigen Daten gelöscht.');
    }

    private function isSoleOwner(TenantUser $membership): bool
    {
        return ! TenantUser::withoutGlobalScopes()->where('tenant_id', $membership->tenant_id)->where('role', 'owner')
            ->where('status', 'active')->where('user_id', '!=', $membership->user_id)->exists();
    }
}
