<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\PraxisFormSubmission;
use App\Models\ProductPurchase;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CouponRedemptionService;
use App\Support\Countries;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ParticipantController extends Controller
{
    /**
     * Detailseite je Teilnehmer: Adresse, gebuchte Kurse/Produkte (inkl.
     * eingelöstem Code je Buchung) und Praxis-Anmeldungen an einem Ort --
     * dieselbe "Buchungen"-Logik wie ProfileController::edit (Lerner sehen
     * ihre eigenen Buchungen dort), hier aus Admin-Sicht für einen
     * bestimmten Teilnehmer der eigenen Bootsschule.
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
            ->with(['course', 'template'])->orderByDesc('created_at')->get();

        return view('admin.participants.show', [
            'participant' => $user,
            'membership' => $membership,
            'bookings' => $bookings,
            'praxisFormSubmissions' => $praxisFormSubmissions,
        ]);
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

        $user = DB::transaction(function () use ($validated, $tenant, $request) {
            $user = User::firstOrCreate(
                ['email' => $validated['email']],
                [
                    'display_name' => $validated['name'],
                    'password' => Hash::make(Str::random(32)),
                    'status' => 'invited',
                    'street' => $validated['street'] ?? null,
                    'postal_code' => $validated['postal_code'] ?? null,
                    'city' => $validated['city'] ?? null,
                    'country' => $validated['country'] ?? null,
                ]
            );

            TenantUser::firstOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                ['role' => 'learner', 'status' => 'active']
            );

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
