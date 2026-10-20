<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Coupon;
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
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ParticipantController extends Controller
{
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
