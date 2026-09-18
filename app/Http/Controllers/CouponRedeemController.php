<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\ProductPurchase;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Einlösen eines vom Superadmin erzeugten Gutschein-Codes -- für einen Kurs
 * (schaltet ein Entitlement frei) oder ein Produkt wie eine Fahrstunde
 * (legt einen ProductPurchase-Nachweis an). Die RLS-Policy auf "coupon"
 * (tenant_id IS NULL OR tenant_id = aktueller Mandant) sorgt bereits dafür,
 * dass ein einer bestimmten Bootsschule zugeordneter Code hier gar nicht
 * erst sichtbar/auffindbar ist, wenn der Nutzer bei einer anderen
 * Bootsschule eingeloggt ist -- ein falscher Code sieht für ihn exakt wie
 * ein unbekannter Code aus.
 */
class CouponRedeemController extends Controller
{
    public function show(): View
    {
        return view('coupons.redeem');
    }

    public function redeem(Request $request, TenantContext $tenantContext, EntitlementService $entitlements): Response
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

        if ($coupon->isForProduct()) {
            return $this->redeemForProduct($coupon, $tenant, $user);
        }

        return $this->redeemForCourse($coupon, $tenant, $user, $entitlements);
    }

    private function redeemForCourse(Coupon $coupon, Tenant $tenant, User $user, EntitlementService $entitlements): Response
    {
        $course = CourseDefinition::withoutGlobalScopes()->find($coupon->course_id);

        if (! $course || ! $entitlements->isOfferable($tenant, $course)) {
            return back()->withErrors(['code' => 'Dieser Kurs ist aktuell nicht verfügbar.'])->withInput();
        }

        $entitlement = DB::transaction(function () use ($coupon, $tenant, $user) {
            $entitlement = Entitlement::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'course_id' => $coupon->course_id,
                'valid_from' => now(),
                'status' => 'active',
                'source_type' => 'coupon',
                'source_reference' => $coupon->code,
            ]);

            $coupon->update([
                'redeemed_by_user_id' => $user->id,
                'redeemed_tenant_id' => $tenant->id,
                'redeemed_at' => now(),
            ]);

            return $entitlement;
        });

        AuditLog::record($tenant->id, $user->id, 'coupon.redeem', 'coupon', $coupon->id, null, ['course_id' => $coupon->course_id]);

        return redirect()->route('courses.show', $entitlement->course_id)
            ->with('status', 'Code eingelöst -- der Kurs ist jetzt freigeschaltet!');
    }

    private function redeemForProduct(Coupon $coupon, Tenant $tenant, User $user): Response
    {
        $product = $coupon->product;

        if (! $product || ! $product->active) {
            return back()->withErrors(['code' => 'Dieses Produkt ist aktuell nicht verfügbar.'])->withInput();
        }

        DB::transaction(function () use ($coupon, $tenant, $user, $product) {
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
                'redeemed_at' => now(),
            ]);
        });

        AuditLog::record($tenant->id, $user->id, 'coupon.redeem', 'coupon', $coupon->id, null, ['product_id' => $product->id]);

        return redirect()->route('profile.edit')
            ->with('status', "Code eingelöst -- \"{$product->name}\" wurde deinem Konto gutgeschrieben!");
    }
}
