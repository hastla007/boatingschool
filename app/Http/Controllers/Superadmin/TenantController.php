<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\Progress;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\BestEffortMail;
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

            // Bug-Review Oberfläche U6: #00A8A8 erreichte mit weißer
            // Buttonschrift nur 2,9:1 Kontrast (WCAG AA verlangt 4,5:1) --
            // siehe TenantBranding::secondaryColorOrDefault().
            TenantBranding::create(['tenant_id' => $tenant->id, 'primary_color' => '#005FD7', 'secondary_color' => '#007A7A']);

            $admin = User::create([
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
        // Bug-Review B31: die Bootsschule ist zu diesem Zeitpunkt schon
        // angelegt -- ein SMTP-Fehler beim Versand soll das nicht mehr als
        // HTTP 500 melden, siehe BestEffortMail.
        $mailSent = BestEffortMail::send(
            fn () => Password::sendResetLink(['email' => $validated['admin_email']]),
            "Einladungsmail neue Bootsschule {$tenant->id}"
        );

        $status = 'Bootsschule angelegt.';

        if (! $mailSent) {
            $status .= ' Die Einladungsmail an den Admin konnte nicht versendet werden -- bitte den Zugang über "Passwort vergessen" erneut anstoßen.';
        }

        return redirect()->route('superadmin.tenants.show', $tenant)->with('status', $status);
    }

    public function show(Tenant $tenant): View
    {
        $tenant->load('branding');

        // Bug-Review Betrieb B6: beide Listen luden bisher JEDEN Nutzer
        // bzw. JEDE Freischaltung dieser Bootsschule ohne Seitenaufteilung
        // -- bei 500 Lernenden wuchs allein das gerenderte HTML dadurch auf
        // mehrere hundert KB (die Nutzerliste blieb per CSS nur visuell auf
        // eine scrollbare Box begrenzt, stand aber vollständig im DOM). Wie
        // bereits bei den drei Listen auf /admin (Bug-Review R4-4) braucht
        // jeder Paginator einen eigenen pageName, da beide Tabellen
        // zusammen auf einer Seite stehen.
        $memberships = TenantUser::where('tenant_id', $tenant->id)->with('user')
            ->orderBy('created_at')
            ->paginate(20, ['*'], 'membership_page')
            ->withQueryString();

        $entitlementsPage = Entitlement::where('tenant_id', $tenant->id)->with('user', 'course.modules.questions')
            ->orderByDesc('valid_from')
            ->paginate(20, ['*'], 'entitlement_page')
            ->withQueryString();

        // Bug-Review Betrieb B6 (dasselbe Muster wie Superadmin\UserController::
        // show(), Bug-Review P1): overallPercent() lief hier einmal PRO
        // Freischaltung und lud dabei jedes Mal frisch sowohl die Modul-/
        // Fragen-Relation des Kurses als auch die Progress-Tabelle nach --
        // bei 500 Lernenden wurde /superadmin/tenants/{id} dadurch mit
        // hunderten zusätzlichen Queries spürbar langsam. Kurs-Module/-Fragen
        // werden deshalb direkt mit eager-geladen, Progress nur für die
        // Nutzer der aktuellen Seite einmal geladen, und die Prozentzahl
        // pro Freischaltung im Speicher berechnet -- exakt dieselbe Formel
        // wie overallPercent().
        $masteredProgress = Progress::where('tenant_id', $tenant->id)
            ->whereIn('user_id', $entitlementsPage->pluck('user_id')->unique())
            ->where('learning_state', 'gefestigt')
            ->get(['user_id', 'question_id']);

        $results = $entitlementsPage->getCollection()->map(function (Entitlement $entitlement) use ($masteredProgress) {
            // Bug-Review F7: siehe overallPercent() -- eine deaktivierte
            // Frage zählt hier nie als gefestigt, aber weiter im Nenner mit.
            $questionIds = $entitlement->course->modules->flatMap(fn ($m) => $m->questions)->where('active', true)->pluck('id')->unique();
            $total = max($questionIds->count(), 1);
            $mastered = $masteredProgress
                ->where('user_id', $entitlement->user_id)
                ->whereIn('question_id', $questionIds)
                ->count();

            return [
                'entitlement' => $entitlement,
                'percent' => (int) round($mastered / $total * 100),
            ];
        });
        $results = $entitlementsPage->setCollection($results);

        // Die Ansicht zeigt ohnehin nur die ersten 10 Codes (siehe
        // superadmin/tenants/show.blade.php) -- bei vielen Codes lud diese
        // Abfrage bisher trotzdem jeden einzelnen vollständig mit allen
        // Relationen, nur um ihn danach wieder zu verwerfen.
        $coupons = Coupon::where('tenant_id', $tenant->id)->orWhere('redeemed_tenant_id', $tenant->id)
            ->with('course', 'product', 'redeemedBy')->orderByDesc('created_at')->take(10)->get();

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

        // Sicherheitsreview S4: Superadmin-Aktionen (hier insbesondere das
        // Sperren/Schließen einer ganzen Bootsschule, betrifft alle ihre
        // Nutzer) wurden bisher gar nicht protokolliert -- im Unterschied
        // zu den längst bestehenden AuditLog::record()-Aufrufen für
        // Bootsschul-Admin-Aktionen (z. B. entitlement.*, coupon.redeem).
        $before = $tenant->only(['name', 'status']);
        $tenant->update($validated);
        AuditLog::record($tenant->id, $request->user()->id, 'superadmin.tenant.update', 'tenant', $tenant->id, $before, $validated);

        return back()->with('status', 'Bootsschule aktualisiert.');
    }
}
