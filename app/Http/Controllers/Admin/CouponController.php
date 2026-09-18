<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\TenantUser;
use App\Services\CouponRedemptionService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zwei Wege, wie eine Bootsschule an Coupon-Codes für ihre eigenen
 * angebotenen Kurse/Produkte kommt: der Superadmin teilt direkt welche zu
 * (Superadmin\CouponController::store mit tenant_id), oder die Bootsschule
 * übernimmt selbst Codes in ihren Bestand (import()), die vorher im System
 * erzeugt (Kurs/Produkt liegt also schon fest) und erst danach über einen
 * externen Webshop verkauft wurden -- welchen Kurs/welches Produkt ein
 * importierter Code freischaltet, muss die Bootsschule deshalb nicht
 * angeben: das steht schon auf dem bestehenden Coupon-Datensatz, den
 * import() nur noch der eigenen tenant_id zuordnet. Aus dem so
 * entstandenen Bestand kann die Bootsschule wiederum einem bereits
 * registrierten Nutzer direkt einen freien Code zuweisen (assign()) --
 * das Gegenstück zum Self-Service-Einlösen durch den Nutzer selbst
 * (CouponRedeemController).
 */
class CouponController extends Controller
{
    public function import(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->tenant();

        $validated = $request->validate([
            'codes' => ['required', 'string'],
        ]);

        $codes = collect(preg_split('/[\s,;]+/', $validated['codes']))
            ->map(fn ($code) => strtoupper(trim($code)))
            ->filter()
            ->unique()
            ->values();

        $claimed = 0;
        $alreadyOwned = 0;
        $redeemed = [];
        $unavailable = [];

        foreach ($codes as $code) {
            // Die RLS-Policy auf "coupon" lässt uns nur Codes sehen, die noch
            // niemandem zugeordnet sind (tenant_id NULL) oder uns bereits
            // gehören -- ein Code einer anderen Bootsschule sieht für uns
            // exakt wie ein unbekannter Code aus (siehe CouponRedeemController).
            $coupon = Coupon::where('code', $code)->first();

            if (! $coupon) {
                $unavailable[] = $code;

                continue;
            }

            if ($coupon->isRedeemed()) {
                $redeemed[] = $code;

                continue;
            }

            if ($coupon->tenant_id === $tenant->id) {
                $alreadyOwned++;

                continue;
            }

            $coupon->update(['tenant_id' => $tenant->id]);
            $claimed++;
        }

        $parts = [$claimed === 1 ? '1 Code übernommen.' : "{$claimed} Codes übernommen."];

        if ($alreadyOwned > 0) {
            $parts[] = "{$alreadyOwned} bereits im eigenen Bestand.";
        }
        if ($redeemed !== []) {
            $parts[] = count($redeemed).' bereits eingelöst: '.$this->shorten($redeemed).'.';
        }
        if ($unavailable !== []) {
            $parts[] = count($unavailable).' unbekannt oder nicht verfügbar: '.$this->shorten($unavailable).'.';
        }

        return back()->with('status', implode(' ', $parts));
    }

    public function assign(Request $request, TenantContext $tenantContext, Coupon $coupon, CouponRedemptionService $redemption): Response
    {
        $tenant = $tenantContext->tenant();
        abort_unless($coupon->tenant_id === $tenant->id && ! $coupon->isRedeemed(), 404);

        $validated = $request->validate([
            'user_id' => ['required', 'uuid'],
        ]);

        $membership = TenantUser::where('user_id', $validated['user_id'])->first();

        if (! $membership) {
            return back()->withErrors(['assign' => 'Dieser Nutzer gehört nicht zu eurer Bootsschule.']);
        }

        try {
            $redemption->redeem($coupon, $tenant, $membership->user, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['assign' => $e->getMessage()]);
        }

        return back()->with('status', 'Code "'.$coupon->code.'" wurde '.$membership->user->name.' zugewiesen.');
    }

    /** @param  array<int, string>  $codes */
    private function shorten(array $codes): string
    {
        return implode(', ', array_slice($codes, 0, 5)).(count($codes) > 5 ? ', …' : '');
    }
}
