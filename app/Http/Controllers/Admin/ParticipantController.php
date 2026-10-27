<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\PraxisFormSubmission;
use App\Models\ProductPurchase;
use App\Models\TenantCourseLocation;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\WillkommensboxOrder;
use App\Notifications\TenantInvitationNotification;
use App\Services\CouponRedemptionService;
use App\Support\BestEffortMail;
use App\Support\Countries;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ParticipantController extends Controller
{
    /**
     * Detailseite je Teilnehmer: Adresse, gebuchte Kurse/Produkte (inkl.
     * eingelöstem Code je Buchung), Praxis-Anmeldungen und Willkommensbox-
     * Bestellung an einem Ort -- dieselbe "Buchungen"-Logik wie
     * ProfileController::edit (Lerner sehen ihre eigenen Buchungen dort),
     * hier aus Admin-Sicht für einen bestimmten Teilnehmer der eigenen
     * Bootsschule.
     */
    public function show(Request $request, TenantContext $tenantContext, User $user): View
    {
        $tenant = $tenantContext->tenant();

        // Diese Seite ist auch für Instructor erreichbar (siehe
        // routes/web.php); Verlängern/Sperren bleibt serverseitig ohnehin
        // auf owner/admin beschränkt (Sicherheitsreview H3) -- das hier
        // vermeidet nur ein unbedienbares Formular für einen Instructor.
        $canManageTenant = $request->user()->isAdminFor($tenant);

        $membership = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
        abort_unless($membership, 404);

        // Sicherheitsreview H3: die "Teilnehmer"-Liste zeigt nur Mitglieder
        // mit role=learner (siehe AdminDashboardController) -- ohne diese
        // Prüfung konnte ein Instructor trotzdem per direktem Request auf
        // die Stammdaten eines Admins/Owners/anderen Instructors derselben
        // Bootsschule zugreifen, einfach indem er dessen User-ID erriet.
        abort_unless($membership->role === 'learner' || $canManageTenant, 404);

        $productPurchases = ProductPurchase::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->with('product')->orderByDesc('created_at')->get();

        // Optionen, die ein Kurs-Coupon zusätzlich bündelt (z. B. "Kleine
        // Praxiseinheit"), landen bei Einlösung als eigene ProductPurchase
        // mit demselben source_reference-Code wie das Kurs-Entitlement
        // (siehe CouponRedemptionService::applyOptionProducts) -- darüber
        // lassen sie sich hier dem jeweiligen Kurs wieder zuordnen, statt
        // als unabhängige Zeile zu erscheinen.
        $optionsByCode = $productPurchases->where('source_type', 'coupon')->groupBy('source_reference');
        $bundledProductIds = [];

        $entitlements = Entitlement::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->with('course')->orderByDesc('valid_from')->get()
            ->map(function (Entitlement $e) use ($optionsByCode, &$bundledProductIds) {
                $options = $e->source_type === 'coupon' ? ($optionsByCode->get($e->source_reference) ?? collect()) : collect();
                $bundledProductIds = [...$bundledProductIds, ...$options->pluck('id')->all()];

                return [
                    'label' => $e->course->name ?? '—',
                    'source_type' => $e->source_type,
                    'source_reference' => $e->source_reference,
                    'date' => $e->valid_from,
                    'status' => $e->status,
                    'options' => $options->map(fn (ProductPurchase $p) => $p->product->name ?? '—')->values(),
                    // Bug-Review B14: nur über diese beiden Felder lässt sich
                    // in der Detailansicht ein "Verlängern"/"Sperren"-Formular
                    // für genau diese Entitlement-Zeile rendern.
                    'entitlement_id' => $e->id,
                    'valid_until' => $e->valid_until,
                ];
            });

        $standaloneProductPurchases = $productPurchases
            ->reject(fn (ProductPurchase $p) => in_array($p->id, $bundledProductIds, true))
            ->map(fn (ProductPurchase $p) => [
                'label' => $p->product->name ?? '—',
                'source_type' => $p->source_type,
                'source_reference' => $p->source_reference,
                'date' => $p->created_at,
                'status' => null,
                'options' => collect(),
                'entitlement_id' => null,
                'valid_until' => null,
            ]);

        $bookings = $entitlements->concat($standaloneProductPurchases)->sortByDesc('date')->values();

        $praxisFormSubmissions = PraxisFormSubmission::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->with(['course', 'template', 'examAppointments.location'])->orderByDesc('created_at')->get();

        // Für das Termin-Vorschlagsformular je Anmeldung: die für den
        // jeweiligen Kurs hinterlegten Orte (siehe TenantCourseLocation),
        // damit die Bootsschule bei mehreren Standorten auswählen kann, wo
        // ein Termin stattfindet -- nach Kurs gruppiert, da verschiedene
        // Anmeldungen unterschiedliche Kurse betreffen können.
        $allLocations = TenantCourseLocation::where('tenant_id', $tenant->id)->orderBy('sort_order')->get();
        $locationsByCourse = $praxisFormSubmissions->pluck('course_id')->unique()
            ->mapWithKeys(fn ($courseId) => [
                $courseId => $allLocations->filter(fn (TenantCourseLocation $l) => in_array($courseId, $l->course_ids ?? [], true))->values(),
            ]);

        $willkommensboxOrder = WillkommensboxOrder::where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();

        // Für die Benachrichtigungsbox neben den Praxis-Anmeldungen: alles,
        // wo die Bootsschule gerade am Zug ist -- eine neue Anmeldung ohne
        // Prüfungstermin-Vorschlag, ein abgelehnter Vorschlag (egal ob
        // Prüfungstermin oder ein zusätzlicher Praxistermin), der einen
        // neuen Vorschlag braucht, oder eine noch nicht versendete
        // Willkommensbox-Bestellung. Ein Vorschlag, der noch auf
        // Rückmeldung des Teilnehmers wartet, ist dagegen nicht an der
        // Bootsschule.
        $actionItems = $praxisFormSubmissions->flatMap(function (PraxisFormSubmission $submission) {
            $items = collect();
            $course = $submission->course->name ?? '—';

            $examAppointments = $submission->appointmentsForSlot(0);
            if ($examAppointments->isEmpty()) {
                $items->push(['course' => $course, 'label' => 'Prüfungstermin', 'reason' => 'Neue Anmeldung -- bitte Prüfungstermin vorschlagen.']);
            } elseif ($examAppointments->last()->response === 'declined') {
                $items->push(['course' => $course, 'label' => 'Prüfungstermin', 'reason' => 'Vorschlag abgelehnt -- bitte neuen Termin vorschlagen.']);
            }

            foreach ($submission->practiceSlotNumbers() as $slot) {
                $latest = $submission->appointmentsForSlot($slot)->last();
                if ($latest && $latest->response === 'declined') {
                    $items->push(['course' => $course, 'label' => $submission->labelForSlot($slot), 'reason' => 'Vorschlag abgelehnt -- bitte neuen Termin vorschlagen.']);
                }
            }

            return $items;
        });

        if ($willkommensboxOrder && ! $willkommensboxOrder->isShipped()) {
            $actionItems->push(['course' => 'Willkommensbox', 'label' => 'Versand', 'reason' => 'Bestellung eingegangen -- bitte Willkommensbox versenden.']);
        }

        return view('admin.participants.show', [
            'participant' => $user,
            'membership' => $membership,
            'canManageTenant' => $canManageTenant,
            'bookings' => $bookings,
            'praxisFormSubmissions' => $praxisFormSubmissions,
            'locationsByCourse' => $locationsByCourse,
            'actionItems' => $actionItems,
            'willkommensboxOrder' => $willkommensboxOrder,
            'hasWillkommensboxAccess' => $user->hasWillkommensboxAccess($tenant),
            'willkommensboxLabelSizes' => $tenant->branding->enabledLabelSizes(),
        ]);
    }

    /**
     * Adresse und Geburtsdatum eines Teilnehmers hinzufügen/ändern --
     * anders als beim Einladen (invite(), nur Adresse, kein Geburtsdatum)
     * darf die Bootsschule hier beides jederzeit nachtragen oder
     * korrigieren, z. B. wenn der Lerner sein Profil noch nicht selbst
     * vervollständigt hat.
     */
    public function update(Request $request, TenantContext $tenantContext, User $user): Response
    {
        $tenant = $tenantContext->tenant();

        $membership = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
        abort_unless($membership, 404);

        // Sicherheitsreview H3: ohne diese Prüfung konnte ein Instructor
        // per direktem Request die Stammdaten eines Admins/Owners/anderen
        // Instructors derselben Bootsschule ändern -- die "Teilnehmer"-UI
        // zeigt ihm nur role=learner an, die Route selbst kannte diese
        // Einschränkung bisher nicht.
        // Bug-Review N11: isAdminFor() behandelt owner/admin als gleich
        // vertrauenswürdig -- ein Admin konnte damit genauso die
        // Stammdaten des Owners (oder eines anderen Admins) ändern wie ein
        // Instructor die eines Admins. Nur der Owner darf hier eine
        // Nicht-Lerner-Mitgliedschaft bearbeiten.
        abort_unless($membership->role === 'learner' || $request->user()->roleForTenant($tenant) === 'owner', 404);

        $validated = $request->validate([
            'street' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', Rule::in(Countries::OPTIONS)],
            'birthdate' => ['nullable', 'date', 'before:today'],
        ]);

        // Sicherheitsreview S3: diese Felder liegen jetzt auf der
        // Mitgliedschaft (tenant_user), nicht mehr kontoweit auf app_user --
        // siehe Migration 000469 und User::hasCompleteRegistrationProfile().
        $before = $membership->only(['street', 'postal_code', 'city', 'country', 'birthdate']);
        $membership->update($validated);

        AuditLog::record($tenant->id, $request->user()->id, 'participant.update', 'app_user', $user->id, $before, $validated);

        return back()->with('status', 'Angaben gespeichert.');
    }

    /**
     * Mitgliedschaft eines Teilnehmers sperren/reaktivieren (Sicherheitsreview
     * M1) -- bisher gab es dafür keine Route, eine gesperrte/entfernte
     * Mitgliedschaft griff also nie. User::roleForTenant() ignoriert eine
     * nicht-aktive Mitgliedschaft; ein gesperrter Teilnehmer verliert damit
     * ab der nächsten Anfrage den Zugang zu dieser Bootsschule, ohne dass
     * seine Sitzung extra beendet werden muss (er bleibt ja ggf. bei
     * anderen Bootsschulen weiter aktiv).
     */
    public function updateMembership(Request $request, TenantContext $tenantContext, User $user): Response
    {
        $tenant = $tenantContext->tenant();

        $membership = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
        abort_unless($membership, 404);

        abort_if($membership->user_id === $request->user()->id, 422, 'Die eigene Mitgliedschaft kann nicht geändert werden.');

        // Bug-Review N11: diese Route liegt hinter tenant.role:owner,admin --
        // ohne diese zusätzliche Prüfung konnte ein Admin die Mitgliedschaft
        // des Owners (oder eines anderen Admins) sperren/schließen.
        abort_unless($membership->role === 'learner' || $request->user()->roleForTenant($tenant) === 'owner', 404);

        $validated = $request->validate([
            'status' => ['required', 'in:active,suspended,closed'],
        ]);

        $before = $membership->only('status');
        $membership->update($validated);

        AuditLog::record($tenant->id, $request->user()->id, 'participant.membership_update', 'tenant_user', $user->id, $before, $validated);

        return back()->with('status', 'Mitgliedschaft aktualisiert.');
    }

    public function invite(Request $request, TenantContext $tenantContext, CouponRedemptionService $redemption): Response
    {
        $tenant = $tenantContext->tenant();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Bug-Review B18: ohne 'lowercase' konnte dieselbe Adresse mit
            // abweichender Schreibweise ein zweites, von Postgres als
            // "anderes" betrachtetes Konto erzeugen (case-sensitiver
            // unique-Vergleich) -- dasselbe Muster wie bereits in
            // RegisteredUserController.
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            // Adresse kann die Bootsschule beim Anlegen (z. B. vor Ort)
            // gleich mit hinterlegen -- das Geburtsdatum bewusst nicht, das
            // muss immer der Lerner selbst angeben (siehe
            // PraxisPruefungController, User::hasCompleteRegistrationProfile).
            'street' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', Rule::in(Countries::OPTIONS)],
            // Ebenfalls optional: ein eigener (z. B. extern verkaufter) Code,
            // der dem neuen Teilnehmer direkt bei der Einladung zugewiesen
            // wird -- Gegenstück zu CouponController::assign, nur in einem
            // Schritt mit der Einladung selbst statt danach separat über die
            // Coupon-Codes-Tabelle.
            'code' => ['nullable', 'string', 'max:255'],
        ]);

        $code = trim((string) ($validated['code'] ?? ''));

        // Bug-Review R4-E (Folgefehler M3/H3-Rest): diese Rollenprüfung galt
        // bisher nur im Neukonto-Zweig weiter unten -- im
        // Bestandskonto-Zweig direkt darunter bekam ein Instructor
        // stattdessen stillschweigend nur den Zusatzsatz "wird dabei nicht
        // automatisch zugewiesen" an den sonst identischen Status angehängt.
        // Beide Antworten unterschieden sich dadurch allein danach, ob zur
        // Adresse schon ein Konto existiert (Fehlermeldung vs. stiller
        // Zusatzsatz) -- ein Instructor konnte so, trotz der M3-Angleichung
        // des Basistexts, über einen beigelegten Code weiter plattformweit
        // prüfen, ob eine beliebige Adresse bereits ein Konto hat. Die
        // Prüfung läuft deshalb jetzt VOR der Existenz-Verzweigung und
        // liefert in beiden Fällen denselben Fehler.
        $codeRequiresAdmin = $code !== '' && ! $request->user()->isAdminFor($tenant);

        // Existiert für diese Adresse schon ein Konto, wird NICHT sofort
        // Mitglied gemacht (Sicherheitsreview H2, Account-Pre-Hijacking):
        // ein Angreifer könnte die Adresse vorab irgendwo auf der Plattform
        // registriert haben, um später automatisch in jede Bootsschule
        // übernommen zu werden, die sie einlädt. Stattdessen entsteht eine
        // Einladung, die nur annehmen kann, wer sich tatsächlich mit dieser
        // Adresse einloggt UND sie bestätigt hat (siehe
        // InvitationAcceptController::accept(), hasVerifiedEmail()-Prüfung
        // dort) -- ein Angreifer, der die Adresse nur vorab registriert hat,
        // kann die ihm fremde Adresse nie bestätigen und die Einladung damit
        // nie für sich selbst annehmen, unabhängig davon, wie lange sein
        // Konto schon unverifiziert existiert.
        //
        // Frühere Fassung (Sicherheitsreview H2) hat ein noch unverifiziertes
        // Konto hier zusätzlich präventiv ausgeloggt und sein Passwort
        // randomisiert -- das schützte nicht zusätzlich (die
        // hasVerifiedEmail()-Prüfung beim Annehmen reicht allein schon aus),
        // machte aber JEDE Einladung an eine noch unbestätigte, aber völlig
        // legitime Adresse (z. B. jemand, der sich gerade erst irgendwo
        // registriert hat) zu einem ungewollten Logout+Passwort-Reset für
        // den echten Kontoinhaber -- und ließ sich von jedem Bootsschul-Admin
        // gegen eine beliebige, ihm fremde Adresse als Belästigung
        // missbrauchen. Entfernt, ohne die eigentliche Schutzwirkung zu
        // verlieren.
        // Sicherheitsreview M7: app_user hat jetzt RLS -- sichtbar sind einer
        // laufenden Anfrage mit gesetztem Tenant-Kontext nur Nutzer, die
        // Mitglied dieser Bootsschule sind. Diese Erkennung muss aber
        // bewusst plattformweit suchen (das ist ihr ganzer Zweck), deshalb
        // hier explizit über die RLS-freie Owner-Verbindung.
        $existingUser = User::on('pgsql_admin')->where('email', $validated['email'])->first();

        if ($existingUser) {
            $invitation = TenantInvitation::create([
                'tenant_id' => $tenant->id,
                'email' => $existingUser->email,
                'role' => 'learner',
                'invited_by_user_id' => $request->user()->id,
            ]);

            // Bug-Review B31: die Einladung ist zu diesem Zeitpunkt schon
            // angelegt -- ein SMTP-Fehler beim Versand soll das nicht mehr
            // als HTTP 500 melden, siehe BestEffortMail.
            $mailSent = BestEffortMail::send(
                fn () => Notification::route('mail', $existingUser->email)->notify(new TenantInvitationNotification($invitation)),
                "Einladungsmail Bestandskonto {$invitation->id}"
            );

            AuditLog::record($tenant->id, $request->user()->id, 'participant.invite_pending', 'tenant_invitation', $invitation->id, null, [
                'email' => $existingUser->email,
            ]);

            // Sicherheitsreview S-N3: "Für diese E-Mail-Adresse existiert
            // bereits ein Konto" bestätigte dem einladenden Bootsschul-Admin
            // direkt und unabhängig von jeder Beziehung zur Bootsschule, ob
            // irgendwo auf der Plattform ein Konto zu dieser Adresse
            // existiert -- missbrauchbar als Enumerations-Orakel über
            // beliebig viele eingeladene Adressen (analog zu M3, nur über
            // diesen authentifizierten Endpunkt statt Login/Passwort-
            // vergessen). Die Formulierung beschreibt jetzt nur noch die
            // tatsächliche Konsequenz (Mitgliedschaft erst nach Bestätigung
            // durch die eingeladene Person), ohne das als "Konto existiert
            // bereits" zu begründen.
            //
            // Bug-Review M3 (Folgefehler): die Formulierung allein hat das
            // Orakel nicht geschlossen -- dieser Zweig (Konto existiert
            // bereits irgendwo auf der Plattform) lieferte bisher einen
            // ANDEREN Text als der Zweig unten (neues Konto), wodurch ein
            // Bootsschul-Admin allein über den Rückgabetext weiter
            // plattformweit prüfen konnte, ob zu einer beliebigen Adresse
            // schon ein Konto existiert. Beide Zweige geben deshalb jetzt
            // denselben Basistext zurück.
            $status = 'Einladung verschickt.';

            if (! $mailSent) {
                $status = 'Einladung angelegt, der Versand der E-Mail ist aber fehlgeschlagen -- bitte später erneut einladen.';
            }

            if ($codeRequiresAdmin) {
                return back()->withErrors(['code' => 'Nur Owner/Admin können beim Einladen direkt einen Code zuweisen -- das geht sonst nach der Einladung über die Coupon-Codes-Verwaltung.'])->with('status', $status);
            }

            if ($code !== '') {
                $status .= ' Ein angegebener Code wird dabei nicht automatisch zugewiesen -- das geht nach der Bestätigung über die Coupon-Codes-Verwaltung.';
            }

            return back()->with('status', $status);
        }

        $user = DB::transaction(function () use ($validated, $tenant, $request) {
            $user = User::create([
                'email' => $validated['email'],
                'display_name' => $validated['name'],
                'password' => Hash::make(Str::random(32)),
                'status' => 'invited',
            ]);

            // Sicherheitsreview S3: Adresse liegt jetzt auf der Mitgliedschaft
            // (tenant_user), nicht mehr kontoweit auf app_user -- siehe
            // Migration 000469.
            TenantUser::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role' => 'learner',
                'status' => 'active',
                'street' => $validated['street'] ?? null,
                'postal_code' => $validated['postal_code'] ?? null,
                'city' => $validated['city'] ?? null,
                'country' => $validated['country'] ?? null,
            ]);

            AuditLog::record($tenant->id, $request->user()->id, 'participant.invite', 'app_user', $user->id, null, [
                'email' => $user->email,
            ]);

            return $user;
        });

        // Bug-Review B18: das neu angelegte Konto bekommt ein zufälliges,
        // niemandem bekanntes Passwort -- ohne eine Mail dazu gab es keine
        // Möglichkeit, sich je einzuloggen. Derselbe Passwort-Reset-Link
        // wie bei "Passwort vergessen" setzt zugleich das erste Passwort.
        // Bug-Review B31: der Teilnehmer ist zu diesem Zeitpunkt schon
        // angelegt -- ein SMTP-Fehler beim Versand soll das nicht mehr als
        // HTTP 500 melden, siehe BestEffortMail.
        $mailSent = BestEffortMail::send(
            fn () => Password::sendResetLink(['email' => $user->email]),
            "Einladungsmail neuer Teilnehmer {$user->id}"
        );

        // Bug-Review M3 (Folgefehler): identischer Basistext wie im
        // "Konto existiert bereits"-Zweig oben, siehe Kommentar dort.
        $status = $mailSent
            ? 'Einladung verschickt.'
            : 'Einladung angelegt, der Versand der E-Mail ist aber fehlgeschlagen -- bitte später erneut einladen.';

        if ($code === '') {
            return back()->with('status', $status);
        }

        // Bug-Review H3 (Rest): /coupons/redeem schließt Instructor bewusst
        // aus (siehe Kommentar dort, routes/web.php) -- über diesen Umweg
        // (Code gleich beim Einladen eines NEUEN Teilnehmers mitgeben, diese
        // Route lässt auch Instructor zu) ließe sich dieselbe Einschränkung
        // sonst umgehen und ein begrenzter Coupon ohne Owner/Admin-Freigabe
        // verbrauchen. Der Teilnehmer selbst bleibt trotzdem angelegt --
        // genau wie bei einem ungültigen Code unten wird nur die
        // Code-Zuweisung abgelehnt, nicht die ganze Einladung.
        if ($codeRequiresAdmin) {
            return back()->withErrors(['code' => 'Nur Owner/Admin können beim Einladen direkt einen Code zuweisen -- das geht sonst nach der Einladung über die Coupon-Codes-Verwaltung.'])->with('status', $status);
        }

        // Eine fehlgeschlagene Code-Zuweisung soll die bereits erfolgte
        // Einladung nicht rückgängig machen -- der Teilnehmer bleibt
        // angelegt, nur der Code-Fehler wird separat gemeldet (die
        // RLS-Policy auf "coupon" lässt uns ohnehin nur Codes sehen, die
        // frei oder bereits uns zugeordnet sind, siehe CouponRedeemController).
        $coupon = Coupon::where('code', strtoupper($code))->whereNull('redeemed_at')->first();

        if (! $coupon) {
            return back()->withErrors(['code' => 'Dieser Code ist ungültig oder wurde bereits eingelöst.'])->with('status', $status);
        }

        try {
            $redemption->redeem($coupon, $tenant, $user, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['code' => $e->getMessage()])->with('status', $status);
        }

        return back()->with('status', $status.' Code "'.$coupon->code.'" wurde eingelöst.');
    }
}
