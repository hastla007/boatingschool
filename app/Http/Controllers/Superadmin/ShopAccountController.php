<?php

namespace App\Http\Controllers\Superadmin;

use App\Commerce\Outbox;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CommerceEvent;
use App\Models\CommerceShopAccount;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\AccountSetupLink;
use App\Support\BestEffortMail;
use App\Support\TenantPath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shopkonten des B2B-Shops prüfen (docs/commerce/README.md): neue
 * Registrierungen einer bestehenden Schule zuordnen oder als neue Schule
 * freigeben, ablehnen; gescheiterte Anlage im Shop erneut anstoßen.
 */
class ShopAccountController extends Controller
{
    public function index(Request $request): View
    {
        $status = is_string($request->query('status')) ? $request->query('status') : 'open';
        $accounts = CommerceShopAccount::with(['user', 'tenant'])
            ->when($status === 'open', fn ($q) => $q->whereIn('status', [CommerceShopAccount::PENDING_REVIEW, CommerceShopAccount::AWAITING_LINK])
                ->orWhere('provisioning_status', 'failed'))
            ->when($status !== 'open' && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')->paginate(30)->withQueryString();
        $failedEvents = CommerceEvent::where('direction', 'out')->where('status', 'failed')
            ->whereIn('shop_account_id', $accounts->pluck('id'))->get()->keyBy('shop_account_id');

        return view('superadmin.shop-accounts.index', [
            'accounts' => $accounts,
            'status' => $status,
            'failedEvents' => $failedEvents,
            'tenants' => Tenant::orderBy('name')->get(['id', 'name', 'slug']),
            'shopConfigured' => Outbox::enabled() || (bool) config('commerce.keys'),
        ]);
    }

    /** Freigeben: bestehende Schule (Rolle Admin) oder neue Schule (Rolle Inhaber). */
    public function approve(Request $request, CommerceShopAccount $account): Response
    {
        abort_unless(in_array($account->status, [CommerceShopAccount::PENDING_REVIEW, CommerceShopAccount::AWAITING_LINK], true), 409, 'Dieses Shopkonto ist nicht mehr in der Prüfung.');
        $validated = $request->validate([
            'mode' => ['required', 'in:existing,new'],
            'tenant_id' => ['nullable', 'required_if:mode,existing', 'uuid', 'exists:tenant,id'],
            'name' => ['nullable', 'required_if:mode,new', 'string', 'max:255'],
            'slug' => ['nullable', 'required_if:mode,new', 'string', 'max:120', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', 'unique:tenant,slug',
                Rule::notIn(array_merge(TenantController::RESERVED_SLUGS, TenantPath::reservedSegments()))],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['slug.regex' => 'Der Slug darf nur Kleinbuchstaben, Ziffern und einzelne Bindestriche enthalten.']);

        // Identität: bei neuer Registrierung das dabei angelegte Konto; ist
        // die Adresse schon ein App-Konto, dieses -- der Superadmin bestätigt
        // die Zuordnung hier ausdrücklich (statt per Verknüpfungscode).
        $user = $account->user_id ? User::find($account->user_id) : User::whereRaw('lower(email) = ?', [strtolower($account->email)])->first();
        if (! $user) {
            throw ValidationException::withMessages(['mode' => 'Zu diesem Shopkonto gibt es kein App-Konto mehr.']);
        }

        [$tenant, $role] = DB::transaction(function () use ($validated, $account, $user, $request) {
            if ($validated['mode'] === 'new') {
                $tenant = Tenant::create(['slug' => $validated['slug'], 'name' => $validated['name'], 'status' => 'active', 'default_locale' => 'de-DE']);
                TenantBranding::create(['tenant_id' => $tenant->id, 'primary_color' => '#005FD7', 'secondary_color' => '#007A7A']);
                $role = 'owner';
            } else {
                $tenant = Tenant::findOrFail($validated['tenant_id']);
                $role = 'admin';
            }
            $membership = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
            if (! $membership) {
                TenantUser::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active']);
            } elseif (! in_array($membership->role, ['owner', 'admin'], true)) {
                // Teilnehmer o. Ä. derselben Schule: Einkauf nur als Admin.
                $membership->forceFill(['role' => 'admin', 'status' => 'active'])->save();
            } else {
                $role = $membership->role;
            }

            $account->forceFill([
                'status' => CommerceShopAccount::LINKED, 'tenant_id' => $tenant->id, 'user_id' => $user->id,
                'reviewed_by_user_id' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => $validated['note'] ?? null,
                'linked_at' => now(),
            ])->save();
            AuditLog::record($tenant->id, $request->user()->id, 'superadmin.commerce.account.approved', 'commerce_shop_account', $account->id, null, [
                'mode' => $validated['mode'], 'email' => $account->email, 'role' => $role,
            ]);

            return [$tenant, $role];
        });

        // Noch nie angemeldet: Einrichtungslink (Passwort festlegen) wie bei Einladungen.
        if ($user->status === 'invited') {
            BestEffortMail::send(fn () => app(AccountSetupLink::class)->send($user, $tenant, $role), 'Shop-Freigabe '.$account->id);
        }

        return back()->with('status', "Shopkonto {$account->email} für „{$tenant->name}“ freigegeben.");
    }

    public function reject(Request $request, CommerceShopAccount $account): Response
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $account->forceFill([
            'status' => CommerceShopAccount::REJECTED, 'reviewed_by_user_id' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => $validated['note'] ?? null,
        ])->save();
        AuditLog::record(null, $request->user()->id, 'superadmin.commerce.account.rejected', 'commerce_shop_account', $account->id, null, ['email' => $account->email]);

        return back()->with('status', "Shopkonto {$account->email} abgelehnt.");
    }

    public function retryProvisioning(CommerceShopAccount $account): Response
    {
        $event = CommerceEvent::where('shop_account_id', $account->id)->where('direction', 'out')->where('status', 'failed')->latest('created_at')->first();
        abort_unless($event, 404);
        Outbox::retry($event);
        app(Outbox::class)->send($event->fresh());

        return back()->with('status', $account->fresh()?->provisioning_status === 'done'
            ? 'Shopkonto angelegt.' : 'Erneut versucht -- der Shop hat noch nicht geantwortet, es wird automatisch wiederholt.');
    }
}
