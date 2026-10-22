<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\TenantUser;
use App\Services\CourseProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class TenantController extends Controller
{
    /**
     * Bug-Review B17: diese Namen würden als Subdomain mit zentraler
     * Infrastruktur kollidieren (www als Alias der Hauptdomain, admin/
     * superadmin mit den gleichnamigen URL-Pfaden dieser App) oder sind
     * allgemein für technische Zwecke reserviert.
     */
    private const RESERVED_SLUGS = ['www', 'admin', 'superadmin', 'api', 'app', 'mail', 'ftp', 'support', 'help'];

    public function index(): View
    {
        $tenants = Tenant::withCount('tenantUsers')->orderBy('name')->get();

        return view('superadmin.tenants.index', ['tenants' => $tenants]);
    }

    public function create(): View
    {
        return view('superadmin.tenants.create');
    }

    public function store(Request $request): Response
    {
        $validated = $request->validate([
            // Bug-Review B17: alpha_dash erlaubt Großbuchstaben und
            // Unterstriche -- der Slug wird aber direkt als Subdomain
            // verwendet (ResolveTenant::resolveViaSubdomain()), und der
            // dortige Tenant::where('slug', ...)-Vergleich ist
            // case-sensitive, während Browser/DNS den Host immer
            // kleinschreiben. Ein Slug mit Großbuchstaben war dadurch nie
            // erreichbar. Erlaubt ist deshalb nur das klassische
            // Kleinbuchstaben-Zahlen-Bindestrich-Format.
            'slug' => [
                'required', 'string', 'max:120',
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                'unique:tenant,slug',
                Rule::notIn(self::RESERVED_SLUGS),
            ],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:trial,active,suspended,closed'],
            'admin_first_name' => ['required', 'string', 'max:255'],
            'admin_last_name' => ['required', 'string', 'max:255'],
            // Bug-Review B18: ohne 'lowercase' konnte dieselbe Adresse mit
            // abweichender Schreibweise ein zweites, von Postgres als
            // "anderes" betrachtetes Konto erzeugen (case-sensitiver
            // unique-Vergleich) -- dasselbe Muster wie bereits in
            // RegisteredUserController.
            'admin_email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:app_user,email'],
        ], [
            'slug.regex' => 'Der Slug darf nur Kleinbuchstaben, Ziffern und einzelne Bindestriche enthalten (z. B. "meine-bootsschule").',
            'slug.not_in' => 'Dieser Slug ist für die Plattform reserviert und kann nicht verwendet werden.',
        ]);

        // Bug-Review B18: die drei Schreib-Operationen liefen bisher ohne
        // Transaktion -- schlug z. B. die TenantUser-Anlage fehl, blieben
        // Tenant/Branding/Admin-Konto als Daten-Fragment ohne Mitgliedschaft
        // zurück.
        $tenant = DB::transaction(function () use ($validated) {
            $tenant = Tenant::create([
                'slug' => $validated['slug'],
                'name' => $validated['name'],
                'status' => $validated['status'],
                'default_locale' => 'de-DE',
            ]);

            TenantBranding::create(['tenant_id' => $tenant->id, 'primary_color' => '#005FD7', 'secondary_color' => '#00A8A8']);

            $admin = \App\Models\User::create([
                'display_name' => trim($validated['admin_first_name'].' '.$validated['admin_last_name']),
                'first_name' => $validated['admin_first_name'],
                'last_name' => $validated['admin_last_name'],
                'email' => $validated['admin_email'],
                'password' => Hash::make(str()->random(32)),
                'status' => 'invited',
            ]);

            TenantUser::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role' => 'owner', 'status' => 'active']);

            return $tenant;
        });

        // Bug-Review B18: das neu angelegte Admin-Konto bekommt ein
        // zufälliges, niemandem bekanntes Passwort -- ohne eine Mail dazu
        // gab es keine Möglichkeit, sich je einzuloggen. Derselbe
        // Passwort-Reset-Link wie bei "Passwort vergessen" setzt zugleich
        // das erste Passwort.
        Password::sendResetLink(['email' => $validated['admin_email']]);

        return redirect()->route('superadmin.tenants.show', $tenant)->with('status', 'Bootsschule angelegt.');
    }

    public function show(Tenant $tenant, CourseProgressService $courseProgress): View
    {
        $tenant->load('branding');

        $memberships = TenantUser::where('tenant_id', $tenant->id)->with('user')->get();

        $entitlements = Entitlement::where('tenant_id', $tenant->id)->with('user', 'course')->get();

        $results = $entitlements->map(function (Entitlement $entitlement) use ($tenant, $courseProgress) {
            return [
                'entitlement' => $entitlement,
                'percent' => $courseProgress->overallPercent($entitlement->course, $tenant, $entitlement->user),
            ];
        });

        $coupons = Coupon::where('tenant_id', $tenant->id)->orWhere('redeemed_tenant_id', $tenant->id)
            ->with('course', 'product', 'redeemedBy')->orderByDesc('created_at')->get();

        return view('superadmin.tenants.show', [
            'tenant' => $tenant,
            'memberships' => $memberships,
            'results' => $results,
            'coupons' => $coupons,
        ]);
    }

    public function update(Request $request, Tenant $tenant): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:trial,active,suspended,closed'],
        ]);

        $tenant->update($validated);

        return back()->with('status', 'Bootsschule aktualisiert.');
    }
}
