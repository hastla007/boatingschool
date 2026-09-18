<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use App\Services\CouponRedemptionService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Einlösen eines Coupon-Codes -- für einen Kurs (schaltet ein Entitlement
 * frei) oder ein Produkt wie eine Fahrstunde (legt einen ProductPurchase-
 * Nachweis an). Die RLS-Policy auf "coupon" (tenant_id IS NULL ODER
 * tenant_id = aktueller Mandant) sorgt bereits dafür, dass ein einer
 * bestimmten Bootsschule zugeordneter Code hier gar nicht erst
 * sichtbar/auffindbar ist, wenn der Nutzer bei einer anderen Bootsschule
 * eingeloggt ist -- ein falscher Code sieht für ihn exakt wie ein
 * unbekannter Code aus. Die eigentliche Einlöse-Logik steckt in
 * CouponRedemptionService, geteilt mit Admin\CouponController::assign
 * (Bootsschul-Admin weist einem Nutzer direkt einen Code zu).
 */
class CouponRedeemController extends Controller
{
    public function show(): View
    {
        return view('coupons.redeem');
    }

    public function redeem(Request $request, TenantContext $tenantContext, CouponRedemptionService $redemption): Response
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $code = strtoupper(trim($validated['code']));

        $coupon = Coupon::where('code', $code)->whereNull('redeemed_at')->first();

        if (! $coupon) {
            return back()->withErrors(['code' => 'Dieser Code ist ungültig oder wurde bereits eingelöst.'])->withInput();
        }

        try {
            $result = $redemption->redeem($coupon, $tenant, $user);
        } catch (RuntimeException $e) {
            return back()->withErrors(['code' => $e->getMessage()])->withInput();
        }

        if ($result['type'] === 'course') {
            return redirect()->route('courses.show', $result['course'])
                ->with('status', 'Code eingelöst -- der Kurs ist jetzt freigeschaltet!');
        }

        return redirect()->route('profile.edit')
            ->with('status', "Code eingelöst -- \"{$result['product']->name}\" wurde deinem Konto gutgeschrieben!");
    }
}
