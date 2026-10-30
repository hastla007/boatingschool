<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\Product;
use App\Models\ProductPurchase;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Einlösen eines Coupons für einen Kurs (Entitlement), ein Produkt
 * (ProductPurchase) oder einen Verlängerungscode (verlängert ein
 * bestehendes Entitlement statt ein neues anzulegen, siehe
 * redeemForRenewal) -- Kernlogik geteilt zwischen dem Self-Service-Einlösen
 * (CouponRedeemController, $user löst für sich selbst ein) und der
 * Zuweisung durch den Bootsschul-Admin an einen bestimmten Nutzer
 * (Admin\CouponController::assign, $user erhält den Code, $actor ist der
 * Admin -- relevant für den Audit-Log-Eintrag).
 */
class CouponRedemptionService
{
    public function __construct(private EntitlementService $entitlements) {}

    /** @return array{type: 'course'|'product'|'course_renewal', course?: CourseDefinition, product?: Product, entitlement?: Entitlement} */
    public function redeem(Coupon $coupon, Tenant $tenant, User $user, ?User $actor = null): array
    {
        $actor ??= $user;

        return DB::transaction(function () use ($coupon, $tenant, $user, $actor) {
            // Zeile sperren und erst danach frisch lesen: verhindert, dass
            // zwei gleichzeitige Einlöse-Versuche desselben Codes (Self-
            // Service-Doppelklick, zwei Konten mit demselben Code, oder
            // Self-Service parallel zur Admin-Zuweisung) beide isRedeemed()
            // == false sehen. Der zweite Request wartet an dieser Stelle,
            // bis der erste committet hat, und bricht danach kontrolliert ab
            // (siehe Sicherheitsreview H1).
            $locked = Coupon::whereKey($coupon->id)->lockForUpdate()->first();

            if (! $locked || $locked->isRedeemed()) {
                throw new RuntimeException('Dieser Code wurde bereits eingelöst.');
            }

            $coupon = $locked;

            if ($coupon->isForRenewal()) {
                return $this->redeemForRenewal($coupon, $tenant, $user, $actor);
            }

            return $coupon->isForProduct()
                ? $this->redeemForProduct($coupon, $tenant, $user, $actor)
                : $this->redeemForCourse($coupon, $tenant, $user, $actor);
        });
    }

    private function redeemForCourse(Coupon $coupon, Tenant $tenant, User $user, User $actor): array
    {
        $course = CourseDefinition::withoutGlobalScopes()->find($coupon->course_id);

        if (! $course || ! $this->entitlements->isOfferable($tenant, $course)) {
            throw new RuntimeException('Dieser Kurs ist aktuell nicht verfügbar.');
        }

        // Bug-Review (Widerrufener Zugang kommt mit neuem Kurscode zurück):
        // hasAccess() unten erkennt nur AKTIVEN Zugang -- ein per Admin\
        // EntitlementController bewusst entzogener ("revoked") Zugang zählt
        // dort nicht als Zugang, sodass ein ganz normaler (neuer) Kurscode
        // für denselben Kurs bisher klaglos ein zweites, aktives Entitlement
        // anlegte. redeemForRenewal() (siehe Bug-Review R4-7) schützt
        // Verlängerungscodes bereits davor, einen entzogenen Zugang so zu
        // unterlaufen -- derselbe Schutz fehlte hier für den regulären
        // Kurscode-Pfad.
        $revoked = Entitlement::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', 'revoked')
            ->exists();

        if ($revoked) {
            throw new RuntimeException($actor->id === $user->id
                ? 'Der Zugang zu diesem Kurs wurde entzogen und kann nicht per Code reaktiviert werden. Bitte wende dich an deine Bootsschule.'
                : 'Der Zugang zu diesem Kurs wurde entzogen und kann nicht per Code reaktiviert werden.');
        }

        // Bug-Review B10: ohne diese Prüfung verbrauchte ein Nutzer, der den
        // Kurs schon freigeschaltet hat, trotzdem den Code und erhielt ein
        // zweites, redundantes Entitlement -- der Code war damit verloren,
        // ohne dass der Nutzer einen Vorteil davon hatte. Eine Verlängerung
        // läuft über einen eigenen Verlängerungscode (redeemForRenewal).
        if ($this->entitlements->hasAccess($tenant, $user, $course)) {
            // Bug-Review (Du/Sie im Admin-Bereich -- weitere Reste): diese
            // Methode läuft sowohl für die Selbst-Einlösung (CouponRedeem
            // Controller, $actor === $user, Lernerbereich siezt bewusst
            // nicht) als auch für Admin\CouponController::assign ($actor
            // ist der Admin, $user der Lerner) -- die Du-Form war dort bis
            // jetzt trotzdem fest eingebaut und erschien dem Admin.
            throw new RuntimeException($actor->id === $user->id
                ? 'Du hast diesen Kurs bereits freigeschaltet. Für eine Verlängerung wird ein Verlängerungscode benötigt.'
                : 'Dieser Nutzer hat diesen Kurs bereits freigeschaltet. Für eine Verlängerung wird ein Verlängerungscode benötigt.');
        }

        $now = now();

        // Bug-Review (Gutschein umgeht Sperre): hasAccess() oben erkennt nur
        // AKTIVEN Zugang -- ein per Admin\EntitlementController::suspend()
        // gesperrtes ("suspended") Entitlement zählt dort nicht als Zugang
        // (genau wie "revoked"), sodass ein ganz normaler (neuer) Kurscode
        // für denselben Kurs bisher klaglos ein ZWEITES, aktives Entitlement
        // anlegte, statt das gesperrte zu reaktivieren -- die Sperre selbst
        // blieb zwar unverändert in der DB stehen, griff aber nicht mehr,
        // weil das neue Entitlement parallel vollen Zugang gewährte. Anders
        // als "revoked" ist "suspended" laut EntitlementController::suspend()
        // ausdrücklich ein "kostenloser, jederzeit rückgängig machbarer
        // Zustand" -- ein Code darf ihn also aufheben, muss dafür aber das
        // BESTEHENDE Entitlement reaktivieren statt ein zweites anzulegen
        // (analog zu redeemForRenewal() oben).
        $suspended = Entitlement::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', 'suspended')
            ->first();

        if ($suspended) {
            $suspended->update([
                'valid_from' => $now,
                'valid_until' => $now->copy()->addDays($tenant->branding->course_validity_days),
                'status' => 'active',
                'source_type' => 'coupon',
                'source_reference' => $coupon->code,
            ]);

            $entitlement = $suspended;
        } else {
            $entitlement = Entitlement::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'course_id' => $coupon->course_id,
                'valid_from' => $now,
                'valid_until' => $now->copy()->addDays($tenant->branding->course_validity_days),
                'status' => 'active',
                'source_type' => 'coupon',
                'source_reference' => $coupon->code,
            ]);
        }

        $this->applyOptionProducts($coupon, $tenant, $user);

        $coupon->update([
            'redeemed_by_user_id' => $user->id,
            'redeemed_tenant_id' => $tenant->id,
            'redeemed_at' => $now,
        ]);

        AuditLog::record($tenant->id, $actor->id, 'coupon.redeem', 'coupon', $coupon->id, null, ['course_id' => $coupon->course_id, 'user_id' => $user->id]);

        return ['type' => 'course', 'course' => $course, 'entitlement' => $entitlement];
    }

    /**
     * Verlängert ein Kurs-Entitlement statt ein neues anzulegen: "Ablauf 1"
     * (Teilnehmer löst selbst über CouponRedeemController ein) und "Ablauf 2"
     * (Bootsschul-Admin weist über Admin\CouponController::assign direkt
     * einem Teilnehmer zu) laufen beide über diese eine Methode. Läuft das
     * bestehende Entitlement noch, verlängert sich sein valid_until ab dem
     * aktuellen Ablaufdatum (nicht ab heute) -- eine ungenutzte Restlaufzeit
     * geht also nicht verloren. Ist es bereits abgelaufen (oder gab es noch
     * nie eines für diesen Kurs), zählt die Frist ab heute. In beiden Fällen
     * wird der Status wieder auf "active" gesetzt, auch wenn er zwischenzeitlich
     * suspendiert war -- ein gekaufter Verlängerungscode soll den Zugang
     * zuverlässig zurückbringen. Ein "revoked" Zugang (siehe weiter unten)
     * ist davon bewusst ausgenommen: das ist die bewusst HÄRTERE
     * Admin-Entscheidung (z. B. bei Betrug/Rückbuchung, Admin\
     * EntitlementController) und soll nicht durch einen vom Lerner selbst
     * eingelösten Code unterlaufen werden können (Bug-Review R4-7).
     */
    private function redeemForRenewal(Coupon $coupon, Tenant $tenant, User $user, User $actor): array
    {
        $course = CourseDefinition::withoutGlobalScopes()->find($coupon->course_id);

        if (! $course || ! $this->entitlements->isOfferable($tenant, $course)) {
            throw new RuntimeException('Dieser Kurs ist aktuell nicht verfügbar.');
        }

        $now = now();
        $days = $tenant->branding->course_validity_days;

        $entitlement = Entitlement::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->orderByDesc('valid_until')
            ->first();

        // Bug-Review R4-7: anders als "suspended" (siehe Kommentar oben) soll
        // ein bewusst entzogener ("revoked") Zugang nicht einfach durch einen
        // Verlängerungscode zurückkommen -- dafür ist eine neue, explizite
        // Admin-Aktion nötig (z. B. erneutes Freischalten über
        // Admin\EntitlementController oder ein neu zugewiesenes Entitlement).
        if ($entitlement && $entitlement->status === 'revoked') {
            // Bug-Review (Du/Sie im Admin-Bereich -- weitere Reste): wie
            // oben bei redeemForCourse() -- der Verweis "wende dich an
            // deine Bootsschule" ergibt für den Admin selbst (der hier über
            // assign() die Bootsschule IST) ohnehin keinen Sinn und entfällt
            // in diesem Fall ganz, statt in die Sie-Form zu wechseln.
            throw new RuntimeException($actor->id === $user->id
                ? 'Der Zugang zu diesem Kurs wurde entzogen und kann nicht per Verlängerungscode reaktiviert werden. Bitte wende dich an deine Bootsschule.'
                : 'Der Zugang zu diesem Kurs wurde entzogen und kann nicht per Verlängerungscode reaktiviert werden.');
        }

        // Bug-Review (AA6: Verlängerungscode bei unbefristetem Zugang
        // wirkungslos): ein bereits AKTIVER, unbefristeter Zugang (valid_
        // until=null) ändert sich durch diesen Code in keiner Weise (siehe
        // $newValidUntil-Fall unten, status bleibt "active") -- der Code
        // wurde trotzdem verbraucht und die Erfolgsmeldung sprach fälschlich
        // von "verlängert". Ein SUSPENDIERTER unbefristeter Zugang bleibt
        // davon bewusst ausgenommen: dort hebt derselbe Code die Sperre auf
        // (status -> active) -- ein echter, gewollter Effekt (siehe Test
        // "does_not_shorten_an_unlimited_entitlement" sowie der
        // $newValidUntil-Kommentar unten), der weiterhin einen Code kosten
        // soll.
        if ($entitlement && $entitlement->status === 'active' && $entitlement->valid_until === null) {
            throw new RuntimeException('Dieser Zugang ist bereits unbefristet gültig.');
        }

        if ($entitlement) {
            // valid_until=null (unbefristet) bleibt unbefristet -- ohne diese
            // Ausnahme wurde ein unbefristeter Zugang hier auf now()+
            // course_validity_days (z. B. 1 Jahr) VERKÜRZT, statt den Zugang
            // nur zurückzubringen (Bug-Review N5). Ein bereits befristeter
            // (laufender oder abgelaufener) Zugang bekommt ein neues,
            // verlängertes valid_until ab dem späteren von jetzt/aktuellem
            // Ablauf, damit eine ungenutzte Restlaufzeit nicht verloren geht.
            $newValidUntil = $entitlement->valid_until === null
                ? null
                : ($entitlement->valid_until->gt($now) ? $entitlement->valid_until : $now)->copy()->addDays($days);

            $entitlement->update(['valid_until' => $newValidUntil, 'status' => 'active']);
        } else {
            $entitlement = Entitlement::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'course_id' => $course->id,
                'valid_from' => $now,
                'valid_until' => $now->copy()->addDays($days),
                'status' => 'active',
                'source_type' => 'coupon_renewal',
                'source_reference' => $coupon->code,
            ]);
        }

        $coupon->update([
            'redeemed_by_user_id' => $user->id,
            'redeemed_tenant_id' => $tenant->id,
            'redeemed_at' => $now,
        ]);

        AuditLog::record($tenant->id, $actor->id, 'coupon.redeem_renewal', 'coupon', $coupon->id, null, [
            'course_id' => $course->id, 'user_id' => $user->id, 'valid_until' => $entitlement->valid_until?->toIso8601String(),
        ]);

        return ['type' => 'course_renewal', 'course' => $course, 'entitlement' => $entitlement];
    }

    /**
     * Ein Kurs-Coupon kann zusätzlich mehrere Produkte bündeln (siehe
     * coupon_option_product, z. B. "Lernplattform inkl. Willkommensbox" und
     * eine Praxiseinheit gleichzeitig) -- bei Einlösung entsteht dafür je
     * Option eine eigene ProductPurchase neben dem Kurs-Entitlement, genau
     * wie bei einem eigenständigen Produkt-Coupon.
     *
     * Für Praxiseinheiten gilt zusätzlich: eine "Große Praxiseinheit"
     * ersetzt eine bereits vorhandene "Kleine Praxiseinheit" -- der Kunde
     * hat danach nur noch die größere Einheit gebucht, nicht beide
     * nebeneinander. Die Löschung läuft vor den Neuanlagen, damit das auch
     * gilt, wenn ein Code beide Praxiseinheiten gleichzeitig bündelt.
     *
     * Bug-Review (Manuelle Freischaltung verliert gebündelte Zusatzprodukte):
     * public, damit Admin\EntitlementController::store() -- das selbst
     * einen Coupon auswählt und verbraucht, statt über redeem() zu laufen --
     * dieselbe Bündel-Logik anwenden kann, statt sie zu duplizieren oder
     * (wie bisher) ganz zu übergehen.
     */
    public function applyOptionProducts(Coupon $coupon, Tenant $tenant, User $user): void
    {
        $options = $coupon->optionProducts;

        if ($options->isEmpty()) {
            return;
        }

        if ($options->contains('code', 'PRAXISEINHEIT-GROSS')) {
            ProductPurchase::where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->whereHas('product', fn ($q) => $q->where('code', 'PRAXISEINHEIT-KLEIN'))
                ->delete();

            // Bündelt derselbe Code ausnahmsweise beide Praxiseinheiten
            // gleichzeitig, zählt nur die größere -- sonst würde der
            // nachfolgende Anlage-Schritt die gerade gelöschte Kleine sofort
            // wieder neu anlegen.
            $options = $options->reject(fn ($option) => $option->code === 'PRAXISEINHEIT-KLEIN');
        }

        foreach ($options as $option) {
            // Bug-Review (Bezahlte Zusatzprodukte stillschweigend nicht
            // angelegt): der T6-Fix prüfte hier, ob der Lerner irgendeine
            // ProductPurchase-Zeile für dasselbe Produkt schon besitzt,
            // unabhängig von Kurs oder Coupon -- ein SBF-See-Code mit
            // "1 Fahrstunde" gefolgt von einem SBF-Binnen-Code mit
            // ebenfalls "1 Fahrstunde" vergab die zweite Fahrstunde
            // dadurch nie. Das eigentliche T6-Problem (doppelte Vergabe
            // beim "Widerruf aufheben") entstand, weil EntitlementController::
            // reactivate() diese Methode für dieselbe, längst gebündelt
            // besessene Zusatzleistung ein zweites Mal aufrief -- behoben
            // dort direkt, indem reactivate() applyOptionProducts() gar
            // nicht mehr aufruft (revoke() löscht die ProductPurchase-Zeile
            // nie, der Lerner bleibt durchgehend Besitzer). Diese Methode
            // legt Zusatzprodukte deshalb wieder wie ursprünglich
            // bedingungslos an.
            ProductPurchase::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'product_id' => $option->id,
                'source_type' => 'coupon',
                'source_reference' => $coupon->code,
            ]);
        }
    }

    private function redeemForProduct(Coupon $coupon, Tenant $tenant, User $user, User $actor): array
    {
        $product = $coupon->product;

        if (! $product || ! $product->active) {
            throw new RuntimeException('Dieses Produkt ist aktuell nicht verfügbar.');
        }

        $now = now();

        ProductPurchase::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'source_type' => 'coupon',
            'source_reference' => $coupon->code,
        ]);

        $coupon->update([
            'redeemed_by_user_id' => $user->id,
            'redeemed_tenant_id' => $tenant->id,
            'redeemed_at' => $now,
        ]);

        AuditLog::record($tenant->id, $actor->id, 'coupon.redeem', 'coupon', $coupon->id, null, ['product_id' => $product->id, 'user_id' => $user->id]);

        return ['type' => 'product', 'product' => $product];
    }
}
