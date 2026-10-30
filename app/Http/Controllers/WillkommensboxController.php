<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\WillkommensboxOrder;
use App\Notifications\WillkommensboxOrderPlaced;
use App\Services\CourseProgressService;
use App\Services\EntitlementService;
use App\Support\BestEffortMail;
use App\Support\Countries;
use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bestellformular für die Willkommensbox (siehe WillkommensboxOrder) --
 * nur erreichbar, wenn der Nutzer "Lernplattform (inkl. Willkommensbox)"
 * eingelöst hat (siehe User::hasWillkommensboxAccess,
 * CouponRedemptionService) UND den bootsschulweiten Kursfortschritt
 * (siehe TenantBranding::willkommensbox_threshold_percent) erreicht hat.
 * Genau wie beim Praxisformular ist die Bestellung einmalig: nach dem
 * Absenden zeigt die Seite nur noch eine Bestätigung, kein erneutes
 * Formular.
 */
class WillkommensboxController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseProgressService $courseProgress): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($user->hasWillkommensboxAccess($tenant), 403, 'Die Willkommensbox ist für dich aktuell nicht freigeschaltet.');

        // Bug-Review (BB6: Bestätigung verschwindet bei abgelaufenem
        // Zugang): eine bereits aufgegebene Bestellung muss unabhängig vom
        // aktuellen Kursfortschritt sichtbar bleiben -- der Fortschritts-
        // Schwellenwert ist nur eine Hürde VOR der erstmaligen Bestellung.
        // progressExactPercent() stützt sich auf activeCourses() und
        // liefert 0, sobald kein Kurszugang mehr aktiv ist (abgelaufenes
        // Entitlement); der Schwellenvergleich lief bisher aber VOR der
        // Suche nach einer bestehenden Bestellung und zeigte in diesem
        // Fall fälschlich wieder den "locked"-Zustand statt der
        // Bestätigung, obwohl die Bestellung (WillkommensboxOrder) längst
        // unverändert in der Datenbank steht.
        $order = WillkommensboxOrder::where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();

        if ($order) {
            return view('willkommensbox.index', ['state' => 'submitted', 'order' => $order]);
        }

        // Bug-Review (Gates vergleichen den gerundeten Wert): siehe
        // store() unten -- das Gate vergleicht den ungerundeten Wert,
        // nur die Anzeige im "locked"-Zustand nutzt den gerundeten.
        $exactPercent = $this->progressExactPercent($tenant, $user, $entitlements, $courseProgress);
        $threshold = $tenant->branding->willkommensbox_threshold_percent;

        if ($exactPercent < $threshold) {
            return view('willkommensbox.index', ['state' => 'locked', 'percent' => (int) round($exactPercent), 'threshold' => $threshold]);
        }

        // Sicherheitsreview S3: Adresse-Vorbefüllung kommt jetzt aus der
        // Mitgliedschaft (tenant_user) dieses Mandanten statt kontoweit von
        // app_user -- siehe Migration 000469.
        $membership = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();

        return view('willkommensbox.index', ['state' => 'form', 'membership' => $membership]);
    }

    public function store(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseProgressService $courseProgress): Response
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($user->hasWillkommensboxAccess($tenant), 403, 'Die Willkommensbox ist für dich aktuell nicht freigeschaltet.');

        // Bug-Review (Gates vergleichen den gerundeten Wert): overallPercent()
        // rundet für die Anzeige -- 49,5% gefestigte Fragen zeigten sich
        // bisher als "50%" und erfüllten damit fälschlich eine Schwelle von
        // 50%. progressExactPercent() vergleicht den tatsächlichen,
        // ungerundeten Fortschritt.
        $exactPercent = $this->progressExactPercent($tenant, $user, $entitlements, $courseProgress);
        $threshold = $tenant->branding->willkommensbox_threshold_percent;
        abort_unless($exactPercent >= $threshold, 403, "Für die Bestellung der Willkommensbox werden mindestens {$threshold}% Kursfortschritt benötigt.");

        $existing = WillkommensboxOrder::where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists();

        if ($existing) {
            return back()->with('status', 'Du hast deine Willkommensbox bereits bestellt.');
        }

        $validated = $request->validate([
            'street' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', Rule::in(Countries::OPTIONS)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        // Bug-Review (Doppelklick-Race): der exists()-Check oben schließt
        // eine echte Gleichzeitigkeit nicht aus (TOCTOU) -- ein Doppelklick
        // konnte zwei Requests mit exists() === false auslösen, von denen
        // der zweite hier gegen den UNIQUE-Constraint (tenant_id, user_id)
        // lief und bisher als ungefangene QueryException mit HTTP 500 endete,
        // analog zum bereits behandelten Fall in ProfileController (R3-6).
        try {
            $order = WillkommensboxOrder::create($validated + [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return back()->with('status', 'Du hast deine Willkommensbox bereits bestellt.');
        }

        // Bug-Review F5: im gesamten Ablauf von Praxis-Anmeldung, Terminen
        // und Willkommensbox wurde bisher keine Mail verschickt -- eine
        // Bootsschule bemerkte den Versandbedarf nur, wenn jemand aktiv in
        // der Teilnehmerliste nachsah.
        $admins = $tenant->admins();
        if ($admins->isNotEmpty()) {
            BestEffortMail::send(
                fn () => Notification::send($admins, new WillkommensboxOrderPlaced($order)),
                "Willkommensbox-Bestellung {$order->id} an Bootsschule {$tenant->id}"
            );
        }

        return redirect()->route('willkommensbox.index');
    }

    /**
     * Die Willkommensbox ist nicht an einen bestimmten Kurs gekoppelt --
     * bei mehreren aktiven Kurszugängen zählt (wie beim Dashboard-Chart
     * "Kursfortschritt in der Lernphase") der am weitesten fortgeschrittene.
     *
     * Bug-Review (Gates vergleichen den gerundeten Wert): ungerundet, für
     * den Schwellenvergleich -- overallPercent() rundet nur für die
     * Anzeige.
     */
    private function progressExactPercent(Tenant $tenant, User $user, EntitlementService $entitlements, CourseProgressService $courseProgress): float
    {
        return $entitlements->activeCourses($tenant, $user)
            ->map(fn ($course) => $courseProgress->exactPercent($course, $tenant, $user))
            ->max() ?? 0.0;
    }
}
