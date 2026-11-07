<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\PraxisFormSubmission;
use App\Models\ProductPurchase;
use App\Models\TenantCourseLocation;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\WillkommensboxOrder;
use App\Services\AccountErasureService;
use App\Services\CouponRedemptionService;
use App\Services\LearnerOverview;
use App\Services\ParticipantInviter;
use App\Support\Countries;
use App\Support\ExamRegistration;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
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
    public function show(Request $request, TenantContext $tenantContext, User $user, LearnerOverview $overview): View
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
        // Kurse mit Selbst-Anmeldung zur Prüfung (noch ohne Termine): dort
        // kann die Schule trotzdem Praxisstunden vorschlagen.
        $selfBookingCourses = Entitlement::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->whereIn('status', ['active', 'suspended'])->with('course')->get()->pluck('course')->filter()
            ->unique('id')
            ->reject(fn ($course) => $praxisFormSubmissions->contains('course_id', $course->id))
            ->filter(fn ($course) => ExamRegistration::isSelf($tenant, $course))
            ->values();

        $allLocations = TenantCourseLocation::where('tenant_id', $tenant->id)->orderBy('sort_order')->get();
        $locationsByCourse = $praxisFormSubmissions->pluck('course_id')->concat($selfBookingCourses->pluck('id'))->unique()
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
            if ($submission->isPracticeOnly()) {
                // Prüfungstermin bucht der Teilnehmer selbst.
            } elseif ($examAppointments->isEmpty()) {
                $items->push(['course' => $course, 'label' => 'Prüfungstermin', 'reason' => 'Neue Anmeldung -- bitte Prüfungstermin vorschlagen.']);
            } elseif (in_array($examAppointments->last()->response, ['declined', 'withdrawn', 'cancelled'], true)) {
                // Review: wie PraxisFormSubmission::nextStep() -- auch nach
                // Zurückziehen bzw. Absage braucht es einen neuen Vorschlag.
                $items->push(['course' => $course, 'label' => 'Prüfungstermin', 'reason' => $examAppointments->last()->response === 'declined' ? 'Vorschlag abgelehnt -- bitte neuen Termin vorschlagen.' : 'Termin zurückgezogen bzw. abgesagt -- bitte neuen Termin vorschlagen.']);
            }

            foreach ($submission->practiceSlotNumbers() as $slot) {
                $latest = $submission->appointmentsForSlot($slot)->last();
                if ($latest && in_array($latest->response, ['declined', 'withdrawn', 'cancelled'], true)) {
                    $items->push(['course' => $course, 'label' => $submission->labelForSlot($slot), 'reason' => $latest->response === 'declined' ? 'Vorschlag abgelehnt -- bitte neuen Termin vorschlagen.' : 'Termin zurückgezogen bzw. abgesagt -- bitte neuen Termin vorschlagen.']);
                }
            }

            return $items;
        });

        if ($willkommensboxOrder && ! $willkommensboxOrder->isShipped()) {
            $actionItems->push(['course' => 'Willkommensbox', 'label' => 'Versand', 'reason' => 'Bestellung eingegangen -- bitte Willkommensbox versenden.']);
        }

        return view('admin.participants.show', [
            'learning' => [
                'courses' => $overview->courses($tenant, $user),
                'last_activity' => $overview->lastActivity($tenant, $user),
                'exams' => $overview->examResults($tenant, $user),
            ],
            'participant' => $user,
            'membership' => $membership,
            'canManageTenant' => $canManageTenant,
            'bookings' => $bookings,
            'praxisFormSubmissions' => $praxisFormSubmissions,
            'selfBookingCourses' => $selfBookingCourses,
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
    /**
     * Lernende(n) samt Lerndaten, Anmeldungen, Bestellungen und Einladungen
     * bei dieser Schule entfernen. Das Konto selbst verschwindet, wenn es
     * keine weitere Mitgliedschaft hat (wie bei der Selbstlöschung).
     */
    public function destroyData(Request $request, TenantContext $tenantContext, User $user, AccountErasureService $erasure): Response
    {
        $tenant = $tenantContext->tenant();
        $membership = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
        abort_unless($membership && $membership->role === 'learner', 404);
        $request->validate(['confirm' => ['required', 'in:LÖSCHEN']], ['confirm.in' => 'Bitte zur Bestätigung LÖSCHEN eintippen.']);
        // Plattform-Konten (Superadmin) löscht nur der Plattformbetreiber.
        if ($user->is_superadmin) {
            return back()->withErrors(['confirm' => 'Dieses Konto gehört zum Plattform-Support und kann hier nicht gelöscht werden. Bitte wenden Sie sich an den Support.']);
        }

        $name = $user->name;
        $erasure->leaveTenant($user, $tenant);
        AuditLog::record($tenant->id, $request->user()->id, 'participant.remove', 'tenant_user', null, null, null);

        return redirect()->route('admin.dashboard', ['tab' => 'participants'])->with('status', "{$name} und alle Lerndaten bei dieser Bootsschule wurden gelöscht.");
    }

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

    public function invite(Request $request, TenantContext $tenantContext, CouponRedemptionService $redemption, ParticipantInviter $inviter): Response
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
        if ($inviter->limitReached($tenant)) {
            return back()->withErrors(['email' => 'Tageslimit von '.ParticipantInviter::DAILY_LIMIT.' Einladungen erreicht. Bitte morgen weiter einladen.'])->withInput();
        }

        $result = $inviter->invite($tenant, $request->user(), $validated['name'], $validated['email'], array_intersect_key($validated, array_flip(['street', 'postal_code', 'city', 'country'])));

        if ($result['duplicate'] ?? false) {
            return back()->with('status', 'Diese Person wurde heute schon eingeladen; die Einladung ist weiter gültig.');
        }
        if ($result['already_member'] ?? false) {
            return back()->with('status', 'Diese Person ist bereits Teilnehmer Ihrer Bootsschule.');
        }
        if ($result['resent'] ?? false) {
            return back()->with('status', $result['mail_sent'] ? 'Einladung erneut verschickt (neuer Link zum Passwort festlegen, 7 Tage gültig).' : 'Der Versand der Einladung ist fehlgeschlagen -- bitte später erneut versuchen.');
        }

        $status = $result['mail_sent']
            ? 'Einladung verschickt.'
            : 'Einladung angelegt, der Versand der E-Mail ist aber fehlgeschlagen -- bitte später erneut einladen.';

        if ($result['type'] === 'existing') {
            if ($codeRequiresAdmin) {
                return back()->withErrors(['code' => 'Nur Owner/Admin können beim Einladen direkt einen Code zuweisen -- das geht sonst nach der Einladung über die Coupon-Codes-Verwaltung.'])->with('status', $status);
            }

            if ($code !== '') {
                $status .= ' Ein angegebener Code wird dabei nicht automatisch zugewiesen -- das geht nach der Bestätigung über die Coupon-Codes-Verwaltung.';
            }

            return back()->with('status', $status);
        }

        $user = $result['user'];

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
            // Review: Datenbankfehler (QueryException ist auch eine
            // RuntimeException) nicht als Formularfehler mit SQL anzeigen.
            throw_if($e instanceof QueryException, $e);

            return back()->withErrors(['code' => $e->getMessage()])->with('status', $status);
        }

        return back()->with('status', $status.' Code "'.$coupon->code.'" wurde eingelöst.');
    }
}
