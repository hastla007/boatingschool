<?php

namespace App\Http\Controllers\Superadmin;

use App\Commerce\Outbox;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CommerceEvent;
use App\Models\CommerceShopAccount;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\AccountErasureService;
use App\Services\AccountSetupLink;
use App\Support\BestEffortMail;
use App\Support\TenantPath;
use App\Support\TenantPicker;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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
            // Anlage im Shop läuft (wartet auf den nächsten Versuch oder wird gerade gesendet).
            ->when($status === 'provisioning', fn ($q) => $q->where('provisioning_status', 'pending'))
            ->when(! in_array($status, ['open', 'all', 'provisioning'], true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')->paginate(30)->withQueryString();
        // Letztes Ausgangs-Ereignis je Konto: pending (wartet), sending (wird
        // gerade gesendet), failed (endgültig fehlgeschlagen) oder done.
        $outEvents = CommerceEvent::where('direction', 'out')->whereIn('shop_account_id', $accounts->pluck('id'))
            ->orderBy('created_at')->get()->keyBy('shop_account_id');

        return view('superadmin.shop-accounts.index', [
            'accounts' => $accounts,
            'status' => $status,
            'outEvents' => $outEvents,
            'shopConfigured' => Outbox::enabled() || (bool) config('commerce.keys'),
        ]);
    }

    /** Freigeben: bestehende Schule (Rolle Admin) oder neue Schule (Rolle Inhaber). */
    public function approve(Request $request, CommerceShopAccount $account): Response
    {
        // Nur neu registrierte Konten. Hat die Adresse schon ein App-Konto
        // (awaiting_link), verknüpft allein der Code aus diesem Konto -- die im
        // Shop angegebene Adresse ist unbestätigt.
        abort_unless($account->status === CommerceShopAccount::PENDING_REVIEW, 409, 'Dieses Shopkonto ist nicht (mehr) zur Freigabe offen.');
        $validated = $request->validate([
            'mode' => ['required', 'in:existing,new'],
            // Schulauswahl per Suchfeld (components/superadmin/tenant-picker);
            // ohne JavaScript Kürzel/Name in tenant_slug (TenantPicker::resolve()).
            'tenant_id' => ['nullable', 'uuid', 'exists:tenant,id'],
            'tenant_slug' => TenantPicker::companionRules(),
            'name' => ['nullable', 'required_if:mode,new', 'string', 'max:255'],
            'slug' => ['nullable', 'required_if:mode,new', 'string', 'max:120', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', 'unique:tenant,slug',
                Rule::notIn(array_merge(TenantController::RESERVED_SLUGS, TenantPath::reservedSegments()))],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['slug.regex' => 'Der Slug darf nur Kleinbuchstaben, Ziffern und einzelne Bindestriche enthalten.']);
        $validated['tenant_id'] = $validated['mode'] === 'existing' ? TenantPicker::resolve($validated, required: true) : null;

        // Identität: Seit der Registrierung nur noch ohne App-Konto (die Adresse
        // ist unbestätigt) -- es entsteht erst hier. Ältere Registrierungen
        // haben es schon (Status „eingeladen“).
        try {
            $result = DB::transaction(function () use ($validated, $account, $request) {
                // Gleichzeitige Freigabe/Ablehnung (zweiter Superadmin, alter Tab).
                $locked = CommerceShopAccount::whereKey($account->id)->lockForUpdate()->first();
                abort_unless($locked && $locked->status === CommerceShopAccount::PENDING_REVIEW, 409, 'Dieses Shopkonto wurde inzwischen bearbeitet.');

                $user = $locked->user_id ? User::find($locked->user_id) : null;
                if (! $user) {
                    if (User::whereRaw('lower(email) = ?', [mb_strtolower($locked->email)])->exists()) {
                        // Inzwischen gibt es ein App-Konto mit dieser Adresse (z. B. selbst
                        // registriert): wie bei der Registrierung nur per Code verknüpfen.
                        $locked->forceFill(['status' => CommerceShopAccount::AWAITING_LINK])->save();
                        AuditLog::record(null, $request->user()->id, 'superadmin.commerce.account.awaiting_link', 'commerce_shop_account', $locked->id, null, []);

                        return null;
                    }
                    $name = $locked->contact_name ?: $locked->email;
                    $user = User::create([
                        'display_name' => $name,
                        ...($locked->contact_name ? User::splitDisplayName($locked->contact_name) : []),
                        'email' => mb_strtolower($locked->email),
                        'password' => Hash::make(Str::random(40)),
                        // Wie eine Einladung: ein Passwort setzt die Person über den Einrichtungslink.
                        'status' => 'invited',
                    ]);
                }

                if ($validated['mode'] === 'new') {
                    $tenant = Tenant::create(['slug' => $validated['slug'], 'name' => $validated['name'], 'status' => 'active', 'default_locale' => 'de-DE']);
                    // 2FA-Pflicht fürs Team nach der Plattform-Einstellung „2FA-Pflicht für neue Schulen“.
                    TenantBranding::create(['tenant_id' => $tenant->id, 'primary_color' => '#005FD7', 'secondary_color' => '#007A7A',
                        'require_team_two_factor' => PlatformSetting::newTenantRequiresTeamTwoFactor()]);
                    $role = 'owner';
                } else {
                    $tenant = Tenant::findOrFail($validated['tenant_id']);
                    $role = 'admin';
                }
                $membership = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
                if (! $membership) {
                    TenantUser::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active']);
                } elseif (in_array($membership->role, ['owner', 'admin'], true) && $membership->status === 'active') {
                    $role = $membership->role;
                } else {
                    // Bestehende Mitgliedschaft nie still hochstufen oder reaktivieren:
                    // das entscheidet die Schule selbst (Team-Verwaltung).
                    throw ValidationException::withMessages(['tenant_id' => 'Diese Person ist bei der Schule bereits '
                        .($membership->status === 'active' ? 'als „'.$membership->role.'“' : 'mit Status „'.$membership->status.'“')
                        .' eingetragen. Rolle bitte über die Team-Verwaltung der Schule ändern lassen.']);
                }

                $account->forceFill([
                    'status' => CommerceShopAccount::LINKED, 'tenant_id' => $tenant->id, 'user_id' => $user->id,
                    'reviewed_by_user_id' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => $validated['note'] ?? null,
                    'linked_at' => now(),
                ])->save();
                AuditLog::record($tenant->id, $request->user()->id, 'superadmin.commerce.account.approved', 'commerce_shop_account', $account->id, null, [
                    'mode' => $validated['mode'], 'user_id' => $user->id, 'role' => $role,
                ]);

                return [$tenant, $role, $user];
            });
        } catch (UniqueConstraintViolationException) {
            // Gleichzeitig ein App-Konto mit derselben Adresse entstanden.
            throw ValidationException::withMessages(['mode' => 'Zu dieser Adresse wurde gerade ein App-Konto angelegt. Bitte die Seite neu laden.']);
        }
        if ($result === null) {
            return back()->withErrors(['mode' => 'Zu dieser Adresse gibt es inzwischen ein App-Konto. Verknüpft wird nur per Code aus diesem Konto; das Shopkonto wartet jetzt auf die Verknüpfung.']);
        }
        [$tenant, $role, $user] = $result;

        // Noch nie angemeldet: Einrichtungslink (Passwort festlegen) wie bei Einladungen.
        if ($user->status === 'invited') {
            BestEffortMail::send(fn () => app(AccountSetupLink::class)->send($user, $tenant, $role), 'Shop-Freigabe '.$account->id);
        }
        // Bestehende Schule: Inhaber erfahren, dass jemand als Admin hinzukam.
        if ($validated['mode'] === 'existing') {
            $ownerEmails = User::whereIn('id', TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)
                ->where('role', 'owner')->where('status', 'active')->where('user_id', '!=', $user->id)->pluck('user_id'))->pluck('email');
            foreach ($ownerEmails as $ownerEmail) {
                BestEffortMail::send(fn () => Mail::raw(
                    "Der Plattform-Betreiber hat {$user->display_name} ({$user->email}) nach Prüfung einer Registrierung im Shop als Admin Ihrer Bootsschule „{$tenant->name}“ eingetragen. "
                    ."Über dieses Konto können Codes für Ihre Schule im Shop gekauft werden.\n\n"
                    .'Falls das nicht stimmt, entfernen Sie die Person im Admin-Bereich unter „Team“ und lösen Sie die Verknüpfung unter „Codes → Shop verknüpfen“.',
                    fn ($m) => $m->to($ownerEmail)->subject('Neuer Admin für Ihre Bootsschule (Shop)')
                ), 'Shop-Freigabe Inhaber-Info '.$account->id);
            }
        }

        return back()->with('status', "Shopkonto {$account->email} für „{$tenant->name}“ freigegeben.");
    }

    public function reject(Request $request, CommerceShopAccount $account): Response
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        DB::transaction(function () use ($account, $validated, $request) {
            $locked = CommerceShopAccount::whereKey($account->id)->lockForUpdate()->first();
            abort_unless($locked && in_array($locked->status, [CommerceShopAccount::PENDING_REVIEW, CommerceShopAccount::AWAITING_LINK], true), 409,
                'Dieses Shopkonto ist nicht (mehr) in der Prüfung.');
            $account->forceFill([
                'status' => CommerceShopAccount::REJECTED, 'reviewed_by_user_id' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => $validated['note'] ?? null,
            ])->save();
            AuditLog::record(null, $request->user()->id, 'superadmin.commerce.account.rejected', 'commerce_shop_account', $account->id, null, []);
        });

        // Das bei der Registrierung angelegte App-Konto wieder entfernen, wenn es
        // nie benutzt wurde -- sonst blockiert die (unbestätigte) Adresse z. B.
        // das spätere Anlegen einer Schule mit ihr als Inhaber.
        $user = $account->user_id ? User::find($account->user_id) : null;
        $unused = $user && $user->status === 'invited' && ! $user->is_superadmin
            && ! TenantUser::withoutGlobalScopes()->where('user_id', $user->id)->exists()
            && ! CommerceShopAccount::where('user_id', $user->id)->where('id', '!=', $account->id)->exists();
        if ($unused) {
            $account->forceFill(['user_id' => null])->save();
            app(AccountErasureService::class)->deleteAccount($user);
        }

        return back()->with('status', "Shopkonto {$account->email} abgelehnt.");
    }

    public function retryProvisioning(CommerceShopAccount $account): Response
    {
        $event = CommerceEvent::where('shop_account_id', $account->id)->where('direction', 'out')->where('status', 'failed')->latest('created_at')->first();
        abort_unless($event, 404);
        Outbox::retry($event);
        app(Outbox::class)->processOne($event->id);

        return back()->with('status', $event->fresh()?->status === 'done'
            ? 'Shopkonto angelegt.' : 'Erneut versucht -- der Shop hat noch nicht geantwortet, es wird automatisch wiederholt.');
    }
}
