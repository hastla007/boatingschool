<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Carbon\Carbon;
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
    public function store(Request $request, TenantContext $tenantContext, EntitlementService $entitlements): Response
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
        $isMember = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists();
        abort_unless($isMember, 404, 'Dieser Nutzer gehört nicht zu eurer Bootsschule.');
        abort_unless($entitlements->isOfferable($tenant, $course), 404, 'Dieser Kurs ist für eure Bootsschule nicht verfügbar.');

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
        return DB::transaction(function () use ($tenant, $user, $course, $request) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$tenant->id.':'.$user->id.':'.$course->id]);

            $entitlement = Entitlement::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('course_id', $course->id)->first();

            if ($entitlement && $entitlement->status === 'active') {
                return back()->with('status', 'Kurs freigeschaltet.');
            }

            $coupon = Coupon::where('tenant_id', $tenant->id)
                ->where('course_id', $course->id)
                ->where('is_renewal', false)
                ->whereNull('redeemed_at')
                ->lockForUpdate()
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

            AuditLog::record($tenant->id, $request->user()->id, 'entitlement.create', 'entitlement', $entitlement->id, $before, $entitlement->fresh()->toArray());

            return back()->with('status', 'Kurs freigeschaltet.');
        });
    }

    public function update(Request $request, TenantContext $tenantContext, Entitlement $entitlement): Response
    {
        abort_unless($entitlement->tenant_id === $tenantContext->id(), 404);

        $validated = $request->validate([
            'action' => ['required', 'in:extend,suspend,revoke,reactivate'],
            // Bug-Review B14 (Folgefehler): siehe store() -- ohne diese
            // Grenze führte "Verlängern" mit einem vergangenen Datum
            // weiterhin zum CHECK-Constraint-Verstoß (HTTP 500).
            'valid_until' => ['required_if:action,extend', 'nullable', 'date', 'after_or_equal:today'],
        ]);

        $before = $entitlement->toArray();

        // Bug-Review B14 (Folgefehler): siehe store() -- derselbe schmale
        // Zeitfenster-Fall (etwa 22/23 bis 24 Uhr UTC) führte auch hier
        // trotz bestandener after_or_equal:today-Validierung weiterhin zum
        // CHECK-Constraint-Verstoß (HTTP 500).
        $validUntil = $validated['action'] === 'extend' ? $this->endOfValidityDay($validated['valid_until']) : null;
        if ($validated['action'] === 'extend' && $validUntil->lte(now())) {
            return back()->withErrors(['valid_until' => 'Das gewählte Datum liegt bereits in der Vergangenheit.']);
        }

        match ($validated['action']) {
            // Bug-Review B14: ein reines Datum ("gültig bis heute") wurde
            // bisher unverändert als valid_until gespeichert -- das
            // entspricht Mitternacht, also dem BEGINN des Tages, nicht
            // seinem Ende. Der Zugang endete damit schon am gewählten Tag
            // selbst statt erst danach, und ein "bis heute" konnte sogar vor
            // valid_from (jetzt) liegen und am CHECK-Constraint der
            // Datenbank scheitern (HTTP 500). endOfValidityDay() verschiebt
            // auf das Ende des gewählten Tages.
            // Bug-Review N6: status wechselt nie automatisch zurück auf
            // "active" (das erledigt nur ExpireEntitlements in Richtung
            // "expired", nie umgekehrt) -- isCurrentlyActive() verlangt
            // aber zwingend status=active, unabhängig von valid_until.
            // Ohne das zusätzliche Update blieb ein bereits abgelaufener
            // (status=expired) Zugang trotz neuem, in der Zukunft
            // liegendem valid_until wirkungslos gesperrt.
            'extend' => $entitlement->update(['valid_until' => $validUntil, 'status' => 'active']),
            'suspend' => $entitlement->update(['status' => 'suspended']),
            'revoke' => $entitlement->update(['status' => 'revoked']),
            'reactivate' => $entitlement->update(['status' => 'active']),
        };

        AuditLog::record(
            $tenantContext->id(), $request->user()->id, 'entitlement.'.$validated['action'],
            'entitlement', $entitlement->id, $before, $entitlement->fresh()->toArray()
        );

        return back()->with('status', 'Entitlement aktualisiert.');
    }

    /**
     * Ein über ein Datumsfeld gewähltes "gültig bis" bezeichnet den letzten
     * Tag, an dem der Zugang noch bestehen soll -- gespeichert wird deshalb
     * das Ende dieses Tages (23:59:59), nicht sein Beginn (Mitternacht).
     * Siehe B14.
     *
     * Bug-Review N18: der Admin wählt diesen Tag im Datums-Feld in
     * deutscher Zeit, aber Carbon::parse() interpretierte ihn ohne
     * Zeitzonen-Angabe in APP_TIMEZONE (bewusst UTC, siehe GermanTime) --
     * endOfDay() endete dadurch tatsächlich erst 1-2 Stunden nach
     * Mitternacht deutscher Zeit des FOLGETAGS, statt wie beabsichtigt am
     * Ende des gewählten Tages in deutscher Zeit.
     */
    private function endOfValidityDay(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value, 'Europe/Berlin')->endOfDay()->setTimezone('UTC') : null;
    }
}
