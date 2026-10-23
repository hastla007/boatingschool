<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Entitlement;
use App\Models\ExamSession;
use App\Models\Progress;
use App\Models\ProductPurchase;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\BestEffortMail;
use App\Support\Countries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        $users = User::with('tenantMemberships.tenant')
            ->when($search !== '', fn ($q) => $q->where(fn ($q2) => $q2
                ->where('display_name', 'ilike', "%{$search}%")
                ->orWhere('email', 'ilike', "%{$search}%")))
            ->orderBy('display_name')
            ->paginate(30)
            ->withQueryString();

        return view('superadmin.users.index', ['users' => $users, 'search' => $search]);
    }

    public function create(): View
    {
        return view('superadmin.users.create', [
            'tenants' => Tenant::orderBy('name')->get(),
            'countries' => Countries::OPTIONS,
        ]);
    }

    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:app_user,email'],
            'tenant_id' => ['nullable', 'uuid', 'exists:tenant,id'],
            'role' => ['required_with:tenant_id', 'nullable', 'in:owner,admin,instructor,staff,learner,support'],
        ]);

        $user = User::create([
            'display_name' => trim($validated['first_name'].' '.$validated['last_name']),
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password' => Hash::make(str()->random(32)),
            'status' => 'invited',
        ]);

        if (! empty($validated['tenant_id'])) {
            TenantUser::create([
                'tenant_id' => $validated['tenant_id'],
                'user_id' => $user->id,
                'role' => $validated['role'] ?? 'learner',
                'status' => 'active',
            ]);
        }

        // Bug-Review N1: das neu angelegte Konto bekommt ein zufälliges,
        // niemandem bekanntes Passwort und bleibt auf status=invited stehen
        // -- ohne eine Mail dazu (wie bereits bei ParticipantController::
        // invite() und Superadmin\TenantController::store()) gab es keine
        // Möglichkeit, je ein eigenes Passwort zu setzen; LoginRequest
        // verlangt status=active, das Konto konnte sich also nie einloggen.
        // Derselbe Passwort-Reset-Link wie bei "Passwort vergessen" setzt
        // zugleich das erste Passwort und schaltet den Status frei (siehe
        // NewPasswordController). Ein SMTP-Fehler beim Versand soll den
        // bereits erfolgreich angelegten Nutzer nicht als HTTP 500 melden
        // (Bug-Review B31, siehe BestEffortMail).
        $mailSent = BestEffortMail::send(
            fn () => Password::sendResetLink(['email' => $user->email]),
            "Einladungsmail neuer Nutzer {$user->id}"
        );

        $status = $mailSent
            ? 'Nutzer angelegt.'
            : 'Nutzer angelegt, die Einladungsmail konnte aber nicht versendet werden -- bitte den Zugang über "Passwort vergessen" erneut anstoßen.';

        return redirect()->route('superadmin.users.show', $user)->with('status', $status);
    }

    public function show(User $user): View
    {
        $memberships = TenantUser::where('user_id', $user->id)->with('tenant')->get();

        $entitlements = Entitlement::where('user_id', $user->id)->with('course.modules.questions', 'tenant')->get();

        // Bug-Review P1: CourseProgressService::overallPercent() lief hier
        // einmal PRO Freischaltung und fragte dabei jedes Mal frisch die
        // Progress-Tabelle ab -- bei einem Nutzer mit vielen Freischaltungen
        // (verschiedene Bootsschulen/Kurse über die Zeit) wurde die Seite
        // dadurch spürbar langsam (N+1). Progress wird deshalb hier einmal
        // komplett geladen und die Prozentzahl pro Freischaltung im
        // Speicher berechnet, exakt dieselbe Formel wie overallPercent().
        $masteredProgress = Progress::where('user_id', $user->id)
            ->where('learning_state', 'gefestigt')
            ->get(['tenant_id', 'question_id']);

        $results = $entitlements->map(function (Entitlement $entitlement) use ($masteredProgress) {
            $questionIds = $entitlement->course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique();
            $total = max($questionIds->count(), 1);
            $mastered = $masteredProgress
                ->where('tenant_id', $entitlement->tenant_id)
                ->whereIn('question_id', $questionIds)
                ->count();

            return [
                'entitlement' => $entitlement,
                'percent' => (int) round($mastered / $total * 100),
            ];
        });

        $examResults = ExamSession::where('user_id', $user->id)->where('status', 'evaluated')
            ->with('course', 'paper')->orderByDesc('submitted_at')->get();

        $productPurchases = ProductPurchase::where('user_id', $user->id)->with('product', 'tenant')
            ->orderByDesc('created_at')->get();

        return view('superadmin.users.show', [
            'user' => $user,
            'memberships' => $memberships,
            'results' => $results,
            'examResults' => $examResults,
            'productPurchases' => $productPurchases,
            'tenants' => Tenant::orderBy('name')->get(),
            'countries' => Countries::OPTIONS,
        ]);
    }

    public function update(Request $request, User $user): Response
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:app_user,email,'.$user->id],
            'status' => ['required', 'in:invited,active,suspended,closed'],
            'phone' => ['nullable', 'string', 'max:40'],
            'street' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'in:'.implode(',', Countries::OPTIONS)],
            'is_superadmin' => ['sometimes', 'boolean'],
        ]);

        $validated['display_name'] = trim($validated['first_name'].' '.$validated['last_name']);

        // is_superadmin ist bewusst nicht $fillable (Sicherheitsreview N4) --
        // Mass-Assignment-Falle für künftigen Code, der versehentlich
        // $request->all() durchreicht. Hier bewusst und explizit per
        // forceFill gesetzt, aus demselben validierten Boolean wie zuvor.
        $isSuperadmin = $request->boolean('is_superadmin');
        unset($validated['is_superadmin']);

        if ($user->email !== $validated['email']) {
            $user->email_verified_at = null;
        }

        $wasActive = $user->status === 'active';
        $user->forceFill(['is_superadmin' => $isSuperadmin]);
        $user->update($validated);

        // Eine Sperrung soll sofort wirken, nicht erst wenn eine laufende
        // Session neu authentifiziert wird (Sicherheitsreview M1). Das
        // "Angemeldet bleiben"-Cookie meldet sonst trotz gelöschter Sessions
        // beim nächsten Request automatisch wieder an -- die Sperrung griffe
        // nur beim nächsten expliziten Login, nicht sofort (Sicherheitsreview
        // M1/N6, analog zum Passwort-Reset in NewPasswordController).
        if ($wasActive && $validated['status'] !== 'active') {
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->forceFill(['remember_token' => Str::random(60)])->save();
        }

        return back()->with('status', 'Nutzer aktualisiert.');
    }

    public function addMembership(Request $request, User $user): Response
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid', 'exists:tenant,id'],
            'role' => ['required', 'in:owner,admin,instructor,staff,learner,support'],
        ]);

        TenantUser::updateOrCreate(
            ['tenant_id' => $validated['tenant_id'], 'user_id' => $user->id],
            ['role' => $validated['role'], 'status' => 'active']
        );

        return back()->with('status', 'Bootsschul-Zuordnung gespeichert.');
    }
}
