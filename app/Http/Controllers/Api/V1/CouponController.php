<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Api\CourseAccess;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Product;
use App\Services\CouponRedemptionService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Zugangscode einlösen (Kurs, Verlängerung oder Produkt) -- dieselbe Logik
 * wie im Web (CouponRedemptionService). Unbekannte, gesperrte, abgelaufene
 * und von anderen eingelöste Codes ergeben einheitlich COUPON_INVALID (kein
 * Ausprobieren, welche Codes existieren). Ein vom selben Konto in derselben
 * Schule bereits eingelöster Code liefert das Ergebnis noch einmal
 * (already_redeemed: true), z. B. nach einem Verbindungsabbruch.
 */
class CouponController extends Controller
{
    public function __construct(private TenantContext $tenantContext, private CourseAccess $access) {}

    public function redeem(Request $request, CouponRedemptionService $redemption): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'min:4', 'max:100']]);
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();

        $coupon = Coupon::lookup($validated['code']);
        if ($coupon && $coupon->isRedeemed() && $coupon->redeemed_by_user_id === $user->id && $coupon->redeemed_tenant_id === $tenant->id) {
            return response()->json(['data' => $this->payload($coupon->course_id ? CourseDefinition::find($coupon->course_id) : null,
                $coupon->product_id ? Product::find($coupon->product_id) : null, $coupon->isForRenewal(), true)]);
        }
        if (! $coupon || ! $coupon->isUsable()) {
            throw $this->invalid();
        }

        try {
            $result = $redemption->redeem($coupon, $tenant, $user);
        } catch (RuntimeException $e) {
            throw_if($e instanceof QueryException, $e);
            // Gleichzeitig von jemand anderem eingelöst oder inzwischen gesperrt.
            if (str_contains($e->getMessage(), 'bereits eingelöst') || str_contains($e->getMessage(), 'nicht mehr gültig')) {
                throw $this->invalid();
            }
            // Fachliche Gründe zum eigenen Konto (z. B. Zugang schon unbefristet).
            throw new ApiProblem(422, 'COUPON_NOT_APPLICABLE', 'Code nicht anwendbar', $e->getMessage());
        }

        return response()->json(['data' => $this->payload($result['course'] ?? null, $result['product'] ?? null, $result['type'] === 'course_renewal', false)]);
    }

    private function payload(?CourseDefinition $course, ?Product $product, bool $renewal, bool $already): array
    {
        $tenant = $this->tenantContext->tenant();

        return [
            'redeemed' => true,
            'already_redeemed' => $already,
            'type' => $product ? 'product' : ($renewal ? 'course_renewal' : 'course'),
            'course_id' => $course?->id,
            'course_name' => $course?->name,
            'entitlement' => $course ? $this->access->status($tenant, request()->user(), $course) : null,
            'product' => $product ? ['id' => $product->id, 'name' => $product->name] : null,
        ];
    }

    private function invalid(): ApiProblem
    {
        return new ApiProblem(422, 'COUPON_INVALID', 'Code ungültig', 'Dieser Code ist ungültig, abgelaufen oder bereits eingelöst.');
    }
}
