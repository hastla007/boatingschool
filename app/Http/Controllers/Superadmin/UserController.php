<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ContentQuestion;
use App\Models\Entitlement;
use App\Models\ExamSession;
use App\Models\ProductPurchase;
use App\Models\Progress;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\BestEffortMail;
use App\Support\Countries;
use App\Support\StatusLabels;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
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
            // Bug-Review R4-6: jede andere Stelle, die eine E-Mail-Adresse
            // anlegt oder ändert (RegisteredUserController,
            // ParticipantController::invite(), ProfileUpdateRequest,
            // Superadmin\TenantController), erzwingt per 'lowercase'-Regel,
            // dass sie klein geschrieben gespeichert wird -- diese Stelle
            // tat das bisher nicht. Ein hier mit Großbuchstaben angelegtes
            // Konto wäre für Password::sendResetLink()/Login (beide lösen
            // case-insensitiv auf den TATSÄCHLICH gespeicherten Wert auf,
            // siehe LoginRequest/PasswordResetLinkController) zwar weiterhin
            // nutzbar, aber app:create-superadmin (lowercased Lookup) oder
            // ein künftiges Konto mit identischer, nur klein geschriebener
            // Adresse hätten diese Zeile nie gefunden bzw. hätten eine
            // Dopplung angelegt (die unique-Regel vergleicht ebenfalls
            // Groß-/Kleinschreibung-sensitiv).
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:app_user,email'],
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

        // Bug-Review (Superadmin-Audit-Lücken): das Anlegen eines Nutzers
        // über diese plattformweite Seite wurde bisher gar nicht
        // protokolliert, anders als z. B. das spätere Sperren/Freigeben
        // über update() (Sicherheitsreview S4).
        AuditLog::record($validated['tenant_id'] ?? null, $request->user()->id, 'superadmin.user.create', 'app_user', $user->id, null, [
            'email' => $user->email,
            'tenant_id' => $validated['tenant_id'] ?? null,
            'role' => $validated['role'] ?? null,
        ]);

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
            // Bug-Review F7: eine deaktivierte Frage zählte hier weiter mit,
            // obwohl sie nie "gefestigt" werden kann (siehe
            // CourseProgressService::overallPercent, dessen Formel hier aus
            // Performance-Gründen dupliziert ist).
            // Bug-Review BB16: dieselbe Lücke bestand für valid_from/valid_to.
            $questionIds = ContentQuestion::currentlyValidIds($entitlement->course->modules->flatMap(fn ($m) => $m->questions));
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
            ->with('course', 'paper', 'questions.revision')->orderByDesc('submitted_at')->get();

        // Bug-Review (FKN offene Fragen): siehe ExamController::result() --
        // dieselbe Selbsteinschätzung-Transparenz auch für den Superadmin,
        // der die Prüfungs-Historie eines Nutzers einsieht.
        $examResults->each(function (ExamSession $session) {
            $openTextQuestions = $session->questions->where('revision.question_type', 'open_text');
            $session->openTextCount = $openTextQuestions->count();
            $session->openTextCorrect = $openTextQuestions->where('correct', true)->count();
        });

        $productPurchases = ProductPurchase::where('user_id', $user->id)->with('product', 'tenant')
            ->orderByDesc('created_at')->get();

        return view('superadmin.users.show', [
            'user' => $user,
            'memberships' => $memberships,
            'results' => $results,
            'examResults' => $examResults,
            'productPurchases' => $productPurchases,
            'tenants' => Tenant::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $user): Response
    {
        // Sicherheitsreview S3: street/postal_code/city/country/birthdate
        // liegen seit Migration 000469 je Mitgliedschaft auf tenant_user,
        // nicht mehr kontoweit auf app_user -- ein Konto kann bei mehreren
        // Bootsschulen Mitglied sein, jede mit ihrer eigenen Kopie. Diese
        // plattformweite Superadmin-Seite kennt keine einzelne Bootsschule,
        // der sie eine Adresse zuordnen könnte; diese Felder editiert
        // deshalb nur noch die jeweilige Bootsschule selbst (Profil/
        // Teilnehmerverwaltung), nicht mehr der Superadmin.
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            // Bug-Review R4-6: siehe store() oben -- dieselbe fehlende
            // 'lowercase'-Regel wie dort.
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:app_user,email,'.$user->id],
            'status' => ['required', 'in:invited,active,suspended,closed'],
            'phone' => ['nullable', 'string', 'max:40'],
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

        // Sicherheitsreview S4: Superadmin-Aktionen -- insbesondere Sperren
        // (status) und das Erteilen/Entziehen von is_superadmin -- wurden
        // bisher gar nicht protokolliert.
        //
        // Bug-Review (Superadmin-Audit-Lücken): email fehlte hier bisher --
        // eine vom Superadmin an dieser Stelle geänderte E-Mail-Adresse
        // (anders als die eigene, bestätigungspflichtige Änderung über
        // ProfileController) wurde dadurch protokolliert, ohne dass
        // nachvollziehbar war, WELCHE Adresse sich geändert hat.
        $before = ['status' => $user->status, 'is_superadmin' => $user->is_superadmin, 'email' => $user->email];

        $wasActive = $user->status === 'active';
        $user->forceFill(['is_superadmin' => $isSuperadmin]);
        $user->update($validated);

        AuditLog::record(null, $request->user()->id, 'superadmin.user.update', 'app_user', $user->id, $before, [
            'status' => $validated['status'],
            'is_superadmin' => $isSuperadmin,
            'email' => $validated['email'],
        ]);

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

        // Sicherheitsreview S4: eine über den Superadmin zugewiesene/
        // geänderte Rolle (z. B. "owner" einer Bootsschule) wurde bisher gar
        // nicht protokolliert.
        $existing = TenantUser::withoutGlobalScopes()
            ->where('tenant_id', $validated['tenant_id'])->where('user_id', $user->id)
            ->first();
        $before = $existing?->only(['role', 'status']);

        // Bug-Review (Superadmin-Rollenänderung hebt Sperre der Bootsschule
        // auf): updateOrCreate() setzte bisher auch bei einer BESTEHENDEN
        // Mitgliedschaft status=active -- eine reine Rollenänderung über
        // dieses Formular (es fragt nur Bootsschule + Rolle ab) hob damit
        // stillschweigend eine von der Bootsschule verhängte Sperre
        // (suspended) oder Entfernung (closed) wieder auf, ohne dass das im
        // Formular erkennbar war. Eine neue Mitgliedschaft startet weiterhin
        // aktiv; eine bestehende behält ihren Status, nur die Rolle ändert
        // sich -- das Entsperren bleibt eine bewusste Entscheidung der
        // Bootsschule (Admin\ParticipantController::updateMembership()).
        if ($existing) {
            $existing->update(['role' => $validated['role']]);
            $status = $existing->status;
        } else {
            TenantUser::create([
                'tenant_id' => $validated['tenant_id'],
                'user_id' => $user->id,
                'role' => $validated['role'],
                'status' => 'active',
            ]);
            $status = 'active';
        }

        AuditLog::record($validated['tenant_id'], $request->user()->id, 'superadmin.user.membership_update', 'tenant_user', $user->id, $before, [
            'role' => $validated['role'], 'status' => $status,
        ]);

        $message = $status === 'active'
            ? 'Bootsschul-Zuordnung gespeichert.'
            : 'Rolle gespeichert. Die Mitgliedschaft bleibt '.StatusLabels::userStatus($status).' -- entsperren kann sie nur die Bootsschule selbst.';

        return back()->with('status', $message);
    }
}
