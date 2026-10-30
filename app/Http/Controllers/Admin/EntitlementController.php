<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CouponRedemptionService;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manuelle Freischaltung laut Umsetzungskonzept: Verlängerung, Sperrung und
 * Widerruf sind eigene, auditierte Aktionen. Lernfortschritt/Attempts werden
 * dabei nie gelöscht -- nur der Entitlement-Status ändert sich.
 */
class EntitlementController extends Controller
{
    public function store(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CouponRedemptionService $redemption): Response
    {
        $tenant = $tenantContext->tenant();

        $validated = $request->validate([
            'user_id' => ['required', 'uuid'],
            'course_id' => ['required', 'uuid'],
        ]);

        $user = User::findOrFail($validated['user_id']);
        $course = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->findOrFail($validated['course_id']);

        // Sicherheitsreview M3: ohne diese beiden Prüfungen ließ sich eine
        // beliebige User-UUID (z. B. aus einer anderen Bootsschule) als
        // Teilnehmer dieser Bootsschule freischalten, und ein sitewide oder
        // mandantenseitig deaktivierter Kurs trotzdem freischalten.
        // Bug-Review T8: "eurer"/"eure" ist die informelle ihr-Form -- der
        // restliche Admin-Bereich siezt durchgehend.
        $isMember = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists();
        abort_unless($isMember, 404, 'Dieser Nutzer gehört nicht zu Ihrer Bootsschule.');
        abort_unless($entitlements->isOfferable($tenant, $course), 404, 'Dieser Kurs ist für Ihre Bootsschule nicht verfügbar.');

        // Bug-Review B14: zwischen der Prüfung "noch nicht gebucht" und dem
        // eigentlichen INSERT lag kein Lock -- ein Doppelklick auf
        // "Freischalten" konnte zwei Entitlement-Zeilen für denselben Kurs
        // anlegen. Der Advisory-Lock serialisiert das; existiert bereits
        // eine Zeile für diese Tenant/Nutzer/Kurs-Kombination (egal welchen
        // Status, z. B. eine zuvor widerrufene), wird sie reaktiviert statt
        // dupliziert.
        //
        // Bug-Review (Hoch): dieser "Kurs freischalten"-Weg legte bisher
        // IMMER ein Entitlement an (source_type=manual, valid_until=null,
        // also unbefristet), ohne jemals einen Coupon-Code zu verbrauchen --
        // das Dropdown im Teilnehmer-Tab zeigt dabei aber ausdrücklich an,
        // wie viele eigene, noch nicht eingelöste Codes die Bootsschule je
        // Kurs besitzt ($freeCourseCouponCounts, AdminDashboardController),
        // und deaktiviert die Option sogar bei 0 ("keine Codes"). Diese
        // Anzeige suggerierte also einen Verbrauch, der nie stattfand: die
        // Freischaltung blieb unbegrenzt oft kostenlos möglich, unabhängig
        // vom tatsächlichen Code-Bestand. Ein verfügbarer, der Bootsschule
        // gehörender Kurs-Coupon ist jetzt Voraussetzung und wird dabei
        // verbraucht -- derselbe Mechanismus wie beim direkten
        // "Code zuweisen" im Coupons-Tab (Admin\CouponController::assign)
        // bzw. der Selbst-Einlösung (CouponRedeemController), nur ohne dass
        // der Admin selbst erst einen konkreten Code heraussuchen muss.
        // Bereits AKTIV freigeschaltete Kurse bleiben dabei bewusst ein
        // kostenloser No-Op (Doppelklick-Schutz oben) -- nur eine
        // Neuvergabe (oder Reaktivierung eines gesperrten/widerrufenen
        // Zugangs) verbraucht einen Code.
        return DB::transaction(function () use ($tenant, $user, $course, $request, $redemption) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$tenant->id.':'.$user->id.':'.$course->id]);

            $entitlement = Entitlement::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('course_id', $course->id)->first();

            // Bug-Review (AA14: Abgelaufener Zugang nicht sofort neu
            // freischaltbar): dieser Doppelklick-Schutz prüfte bisher nur
            // status === 'active', nicht aber valid_until -- ein Zugang,
            // dessen Gültigkeit bereits verstrichen ist, trägt in der
            // Datenbank aber so lange weiter status=active, bis der
            // stündliche app:expire-entitlements-Scheduler (siehe dessen
            // Command) ihn auf 'expired' umstellt (Entitlement::
            // scopeCurrentlyActive() prüft valid_until deshalb bewusst
            // unabhängig vom Status). Ein Admin, der genau in diesem
            // Zeitfenster erneut freischalten wollte, bekam fälschlich
            // sofort "Kurs freigeschaltet" zurück, ohne dass Laufzeit oder
            // Coupon tatsächlich angefasst wurden -- der Lerner blieb bis
            // zum nächsten Scheduler-Lauf gesperrt.
            $isCurrentlyActive = $entitlement
                && $entitlement->status === 'active'
                && ($entitlement->valid_until === null || $entitlement->valid_until->isFuture());

            if ($isCurrentlyActive) {
                return back()->with('status', 'Kurs freigeschaltet.');
            }

            // Bug-Review F1: analog zu CouponRedemptionService::
            // redeemForRenewal() (Bug-Review R4-7) soll ein bewusst
            // widerrufener Zugang nicht einfach durch einen neuen Code
            // zurückkommen -- sonst ließe sich die bewusst HÄRTERE
            // "Widerrufen"-Entscheidung (z. B. bei Betrug/Rückbuchung)
            // allein dadurch unterlaufen, dass irgendwann wieder ein freier
            // Code für diesen Kurs verfügbar ist. Dafür ist die eigene,
            // ausdrückliche "Widerruf aufheben"-Aktion nötig (siehe update()).
            if ($entitlement && $entitlement->status === 'revoked') {
                return back()->withErrors(['course_id' => 'Der Zugang zu diesem Kurs wurde widerrufen und kann nicht einfach per neuem Code wiederhergestellt werden -- bitte zuerst den Widerruf bei der bestehenden Buchung aufheben.']);
            }

            // Bug-Review (Manuelle Freischaltung -- Code beliebig gewählt):
            // ohne ORDER BY lieferte Postgres hier eine für den Admin nicht
            // nachvollziehbare, letztlich zufällige Reihenfolge -- welcher
            // der ggf. mehreren freien Codes für diesen Kurs verbraucht
            // wird, war damit reiner Zufall. ->oldest() macht das
            // deterministisch (der am längsten ungenutzte Code zuerst) und
            // damit nachvollziehbar.
            // Bug-Review (Gleichzeitige Code-Zuweisung meldet "kein Code
            // frei"): ->oldest()->lockForUpdate() sperrt genau DIE eine
            // Zeile, die ORDER BY + LIMIT 1 auswählt. Weisen zwei Admins
            // nahezu gleichzeitig denselben Kurs zu und sind mehrere Codes
            // frei, wartet die zweite Transaktion bisher auf exakt diese
            // eine (schon vom ersten Admin gesperrte) Zeile -- nach deren
            // Commit erfüllt sie wegen des inzwischen gesetzten redeemed_at
            // die WHERE-Bedingung nicht mehr, Postgres schließt sie über
            // EvalPlanQual einfach aus, ohne automatisch zur nächstältesten
            // freien Zeile weiterzurücken: das Ergebnis ist 0 Zeilen, obwohl
            // ein zweiter freier Code längst existierte. SKIP LOCKED lässt
            // die zweite Transaktion stattdessen direkt die nächste, noch
            // ungesperrte freie Zeile wählen.
            $coupon = Coupon::where('tenant_id', $tenant->id)
                ->where('course_id', $course->id)
                ->where('is_renewal', false)
                ->whereNull('redeemed_at')
                ->oldest()
                ->lock('for update skip locked')
                ->first();

            if (! $coupon) {
                return back()->withErrors(['course_id' => 'Für diesen Kurs ist kein freier Code mehr vorhanden.']);
            }

            $before = $entitlement?->toArray();

            $attributes = [
                'valid_from' => now(),
                'valid_until' => now()->copy()->addDays($tenant->branding->course_validity_days),
                'status' => 'active',
                'source_type' => 'coupon',
                'source_reference' => $coupon->code,
            ];

            if ($entitlement) {
                $entitlement->update($attributes);
            } else {
                $entitlement = Entitlement::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'course_id' => $course->id,
                    ...$attributes,
                ]);
            }

            $coupon->update([
                'redeemed_by_user_id' => $user->id,
                'redeemed_tenant_id' => $tenant->id,
                'redeemed_at' => now(),
            ]);

            // Bug-Review (Manuelle Freischaltung verliert gebündelte
            // Zusatzprodukte): dieser Weg erzeugte das Entitlement bisher
            // komplett losgelöst von CouponRedemptionService::
            // redeemForCourse() -- bündelte der hier verbrauchte Coupon
            // zusätzliche Produkte (z. B. "Lernplattform inkl.
            // Willkommensbox", siehe coupon_option_product), gingen diese
            // stillschweigend verloren, weil applyOptionProducts() nie
            // aufgerufen wurde. Der Lerner hatte danach den Kurs, aber
            // keines der mitgekauften Zusatzprodukte.
            $redemption->applyOptionProducts($coupon, $tenant, $user);

            AuditLog::record($tenant->id, $request->user()->id, 'entitlement.create', 'entitlement', $entitlement->id, $before, $entitlement->fresh()->toArray());

            return back()->with('status', 'Kurs freigeschaltet.');
        });
    }

    /**
     * Bug-Review (Kostenlose Umgehungen -- Verlängern/Widerruf aufheben):
     * "Verlängern" ließ bisher ein beliebiges, frei gewähltes Datum zu
     * (auch weit in der Zukunft, z. B. 2099) -- völlig unabhängig von den
     * eigens dafür verkauften Verlängerungscodes (is_renewal=true), die
     * damit komplett umgangen wurden. "Widerruf aufheben" stellte einen
     * bewusst entzogenen Zugang sogar ganz ohne jeden Code wieder her, was
     * genau die in store() (Bug-Review F1) beschriebene härtere
     * Widerrufen-Entscheidung im Ergebnis bedeutungslos machte: revoke()
     * gefolgt von reactivate() kostete zusammen nichts, stellte den
     * vollen Zugang aber wieder her. Beide Aktionen verbrauchen jetzt
     * denselben Coupon-Mechanismus wie jede andere Freischaltung --
     * "Verlängern" einen Verlängerungscode (analog zu
     * CouponRedemptionService::redeemForRenewal()), "Widerruf aufheben"
     * einen regulären Kurs-Code (analog zu store()). "Sperren"/
     * "Entsperren" bleiben dagegen bewusst kostenlos: sie rühren
     * valid_until nie an, ein entsperrter Zugang bekommt also nur die
     * ohnehin schon bezahlte Restlaufzeit zurück, keinen zusätzlichen
     * Wert.
     */
    public function update(Request $request, TenantContext $tenantContext, Entitlement $entitlement): Response
    {
        abort_unless($entitlement->tenant_id === $tenantContext->id(), 404);

        $validated = $request->validate([
            'action' => ['required', 'in:extend,suspend,revoke,reactivate'],
        ]);

        $tenant = $tenantContext->tenant();

        return DB::transaction(function () use ($validated, $entitlement, $tenant, $request) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$tenant->id.':'.$entitlement->user_id.':'.$entitlement->course_id]);
            $entitlement->refresh();

            $before = $entitlement->toArray();

            $error = match ($validated['action']) {
                'extend' => $this->extend($entitlement, $tenant),
                'suspend' => $this->suspend($entitlement),
                'revoke' => $this->revoke($entitlement),
                'reactivate' => $this->reactivate($entitlement, $tenant),
            };

            if ($error) {
                return $error;
            }

            AuditLog::record(
                $tenant->id, $request->user()->id, 'entitlement.'.$validated['action'],
                'entitlement', $entitlement->id, $before, $entitlement->fresh()->toArray()
            );

            return back()->with('status', 'Entitlement aktualisiert.');
        });
    }

    /**
     * Verlängert um tenant_branding.course_validity_days ab dem späteren
     * von aktuellem valid_until und jetzt -- eine ungenutzte Restlaufzeit
     * geht dadurch nicht verloren (analog zu CouponRedemptionService::
     * redeemForRenewal()). unbefristete Zugänge (valid_until=null) lassen
     * sich nicht weiter verlängern, da sie bereits keine Grenze haben.
     *
     * Bug-Review F1 (weiterhin gültig): der Status selbst bleibt dabei
     * unberührt, außer er wechselt aus "expired" zurück zu "active" (Bug-
     * Review N6) -- eine bewusste Sperrung oder ein Widerruf lässt sich
     * dadurch weiterhin nicht allein durch eine neue Laufzeit aufheben.
     */
    private function extend(Entitlement $entitlement, Tenant $tenant): ?RedirectResponse
    {
        // Bug-Review T5: ohne diese Prüfung verbrauchte "Verlängern" bei
        // einem widerrufenen Zugang trotzdem einen vollwertigen
        // Verlängerungscode und erweiterte valid_until -- der Status
        // selbst bleibt laut Kommentar oben aber bewusst unberührt, außer
        // er wechselt von "expired" zu "active" (Bug-Review N6). Bei
        // status=revoked greift das nie, der Code verfiel also wirkungslos:
        // der Zugang blieb weiterhin gesperrt, EntitlementService prüft
        // status unabhängig von valid_until. Reaktivieren eines Widerrufs
        // läuft stattdessen ausschließlich über die eigene
        // "Widerruf aufheben"-Aktion (reactivate()), die dafür einen
        // regulären Kurs-Code verlangt.
        if ($entitlement->status === 'revoked') {
            return back()->withErrors(['action' => 'Der Zugang zu diesem Kurs wurde widerrufen und lässt sich nicht einfach verlängern -- bitte zuerst den Widerruf aufheben.']);
        }

        if ($entitlement->valid_until === null) {
            return back()->withErrors(['action' => 'Dieser Zugang ist bereits unbefristet gültig.']);
        }

        // Bug-Review (Gleichzeitige Code-Zuweisung meldet "kein Code frei"):
        // siehe grant() oben -- SKIP LOCKED statt lockForUpdate().
        $coupon = Coupon::where('tenant_id', $tenant->id)
            ->where('course_id', $entitlement->course_id)
            ->where('is_renewal', true)
            ->whereNull('redeemed_at')
            ->oldest()
            ->lock('for update skip locked')
            ->first();

        if (! $coupon) {
            return back()->withErrors(['action' => 'Für diesen Kurs ist kein freier Verlängerungscode mehr vorhanden.']);
        }

        $base = $entitlement->valid_until->gt(now()) ? $entitlement->valid_until : now();

        $entitlement->update([
            'valid_until' => $base->copy()->addDays($tenant->branding->course_validity_days),
            'status' => $entitlement->status === 'expired' ? 'active' : $entitlement->status,
        ]);

        $coupon->update([
            'redeemed_by_user_id' => $entitlement->user_id,
            'redeemed_tenant_id' => $tenant->id,
            'redeemed_at' => now(),
        ]);

        return null;
    }

    /** Kostenloser, jederzeit rückgängig machbarer Zustand -- rührt valid_until nie an. */
    private function suspend(Entitlement $entitlement): ?RedirectResponse
    {
        $entitlement->update(['status' => 'suspended']);

        return null;
    }

    private function revoke(Entitlement $entitlement): ?RedirectResponse
    {
        $entitlement->update(['status' => 'revoked']);

        return null;
    }

    /**
     * "Entsperren" (status=suspended) bleibt kostenlos: die Sperrung hat
     * nie valid_until verändert, es wird also kein zusätzlicher Zugang
     * über das bereits Bezahlte hinaus gewährt, nur die Pause
     * rückgängig gemacht. "Widerruf aufheben" (status=revoked) braucht
     * dagegen einen vollwertigen Kurs-Code, genau wie eine Neuvergabe
     * über store() -- inklusive frischer Laufzeit. Gebündelte
     * Zusatzprodukte werden hier NICHT erneut vergeben, siehe
     * Bug-Review-Kommentar unten.
     */
    private function reactivate(Entitlement $entitlement, Tenant $tenant): ?RedirectResponse
    {
        if ($entitlement->status !== 'revoked') {
            $entitlement->update(['status' => 'active']);

            return null;
        }

        // Bug-Review (Gleichzeitige Code-Zuweisung meldet "kein Code frei"):
        // siehe grant() oben -- SKIP LOCKED statt lockForUpdate().
        $coupon = Coupon::where('tenant_id', $tenant->id)
            ->where('course_id', $entitlement->course_id)
            ->where('is_renewal', false)
            ->whereNull('redeemed_at')
            ->oldest()
            ->lock('for update skip locked')
            ->first();

        if (! $coupon) {
            return back()->withErrors(['action' => 'Für diesen Kurs ist kein freier Code mehr vorhanden, um den Widerruf aufzuheben.']);
        }

        $entitlement->update([
            'valid_from' => now(),
            'valid_until' => now()->copy()->addDays($tenant->branding->course_validity_days),
            'status' => 'active',
            'source_type' => 'coupon',
            'source_reference' => $coupon->code,
        ]);

        $coupon->update([
            'redeemed_by_user_id' => $entitlement->user_id,
            'redeemed_tenant_id' => $tenant->id,
            'redeemed_at' => now(),
        ]);

        // Bug-Review (Bezahlte Zusatzprodukte stillschweigend nicht
        // angelegt): hier stand bisher applyOptionProducts($coupon, ...),
        // um dasselbe gebündelte Zusatzprodukt (z. B. die Willkommensbox)
        // erneut zu vergeben -- revoke() oben löscht aber nie die
        // ProductPurchase-Zeile der ursprünglichen Einlösung, der Lerner
        // besitzt das Produkt also bereits durchgehend über den gesamten
        // Widerrufen/Aufheben-Zyklus. Der erneute Aufruf war daher nie
        // nötig und legte (nach dem T6-Fix) wegen der dortigen, zu breiten
        // Dopplungsprüfung sogar ein per ANDEREM Kurs/Coupon gebündeltes,
        // aber zufällig gleiches Produkt fälschlich gar nicht erst an.

        return null;
    }
}
