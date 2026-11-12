<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Api\CourseAccess;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Product;
use App\Services\CouponRedemptionService;
use App\Services\LearningGroupService;
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

    public function redeem(Request $request, CouponRedemptionService $redemption, LearningGroupService $groups): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'min:4', 'max:100']]);
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $raw = trim($validated['code']);
        $groupCode = preg_match('#/gruppe/([gi][0-9a-f]{32})/?#i', $raw, $match) === 1 ? strtolower($match[1]) : strtolower($raw);
        if (preg_match('/^[gi][0-9a-f]{32}$/', $groupCode) === 1) {
            try {
                $joined = $groups->join($groupCode, $tenant, $user);
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'GROUP_SCOPE_UNSUPPORTED') {
                    throw new ApiProblem(422, 'GROUP_SCOPE_UNSUPPORTED', 'Gruppenumfang nicht unterstützt', 'Für diese Gruppenlizenz ist noch kein passender Original-Kurszugang eingerichtet.');
                }
                throw $this->invalid();
            }
            $codes = collect($joined['group']->scopes ?? [])->map(fn ($scope) => $scope['course_code'] ?? $scope['course'] ?? null)->filter()->unique()->values();
            $courses = CourseDefinition::withoutGlobalScopes()->whereIn('code', $codes)->where('status', 'published')->get()
                ->map(fn (CourseDefinition $course) => ['course_id' => $course->id, 'code' => $course->code, 'name' => $course->name])->values();

            return response()->json(['data' => [
                'redeemed' => true,
                'already_redeemed' => $joined['already'],
                'type' => 'group_license',
                'group_name' => $joined['group']->name,
                'courses' => $courses,
                'entitlement' => ['status' => 'active', 'valid_from' => null, 'valid_until' => $joined['group']->valid_until?->toIso8601String()],
            ]]);
        }

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
