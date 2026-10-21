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
use App\Support\Countries;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
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
    public function show(TenantContext $tenantContext, User $user): View
    {
        $tenant = $tenantContext->tenant();

        $membership = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
        abort_unless($membership, 404);

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

        $validated = $request->validate([
            'street' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', Rule::in(Countries::OPTIONS)],
            'birthdate' => ['nullable', 'date', 'before:today'],
        ]);

        $before = $user->only(['street', 'postal_code', 'city', 'country', 'birthdate']);
        $user->update($validated);

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
            'email' => ['required', 'email', 'max:255'],
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

        // Existiert für diese Adresse schon ein Konto, wird NICHT sofort
        // Mitglied gemacht (Sicherheitsreview H2, Account-Pre-Hijacking):
        // ein Angreifer könnte die Adresse vorab irgendwo auf der Plattform
        // registriert haben, um später automatisch in jede Bootsschule
        // übernommen zu werden, die sie einlädt. Stattdessen entsteht eine
        // Einladung, die nur annehmen kann, wer sich tatsächlich mit dieser
        // Adresse einloggt (siehe InvitationAcceptController). Ein noch
        // unverifiziertes Konto ist zusätzlich verdächtig genug, um
        // vorsorglich dessen laufende Sitzungen zu beenden und das Passwort
        // zu ändern -- der echte Besitzer bekommt sein Konto über "Passwort
        // vergessen" zurück, ein Angreifer verliert den Zugriff sofort.
        $existingUser = User::where('email', $validated['email'])->first();

        if ($existingUser) {
            if (! $existingUser->hasVerifiedEmail()) {
                DB::table('sessions')->where('user_id', $existingUser->id)->delete();
                $existingUser->forceFill(['password' => Hash::make(Str::random(40))])->save();
            }

            $invitation = TenantInvitation::create([
                'tenant_id' => $tenant->id,
                'email' => $existingUser->email,
                'role' => 'learner',
                'invited_by_user_id' => $request->user()->id,
            ]);

            Notification::route('mail', $existingUser->email)->notify(new TenantInvitationNotification($invitation));

            AuditLog::record($tenant->id, $request->user()->id, 'participant.invite_pending', 'tenant_invitation', $invitation->id, null, [
                'email' => $existingUser->email,
            ]);

            $status = 'Für diese E-Mail-Adresse existiert bereits ein Konto. Eine Einladung wurde verschickt -- die Mitgliedschaft entsteht erst, wenn der Kontoinhaber sie selbst bestätigt.';

            if (trim((string) ($validated['code'] ?? '')) !== '') {
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
                'street' => $validated['street'] ?? null,
                'postal_code' => $validated['postal_code'] ?? null,
                'city' => $validated['city'] ?? null,
                'country' => $validated['country'] ?? null,
            ]);

            TenantUser::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role' => 'learner',
                'status' => 'active',
            ]);

            AuditLog::record($tenant->id, $request->user()->id, 'participant.invite', 'app_user', $user->id, null, [
                'email' => $user->email,
            ]);

            return $user;
        });

        $status = 'Teilnehmer eingeladen/zugeordnet.';
        $code = trim((string) ($validated['code'] ?? ''));

        if ($code === '') {
            return back()->with('status', $status);
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
