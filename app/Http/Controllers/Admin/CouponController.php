<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Product;
use App\Models\TenantUser;
use App\Services\CouponRedemptionService;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zwei Wege, wie eine Bootsschule an Coupon-Codes für ihre eigenen
 * angebotenen Kurse/Produkte kommt: extern (bei einem Webshop) einzeln oder
 * im Bulk gekaufte Codes werden hier importiert (import()), oder der
 * Superadmin teilt direkt welche zu (Superadmin\CouponController::store
 * mit tenant_id). Aus dem so entstandenen Bestand kann die Bootsschule
 * wiederum einem bereits registrierten Nutzer direkt einen freien Code für
 * einen Kurs zuweisen (assign()) -- das Gegenstück zum Self-Service-
 * Einlösen durch den Nutzer selbst (CouponRedeemController).
 */
class CouponController extends Controller
{
    public function import(Request $request, TenantContext $tenantContext, EntitlementService $entitlements): Response
    {
        $tenant = $tenantContext->tenant();

        $validated = $request->validate([
            'target' => ['required', 'string'],
            'codes' => ['required', 'string'],
        ]);

        [$type, $targetId] = array_pad(explode(':', $validated['target'], 2), 2, null);

        if ($type === 'course') {
            $course = $targetId ? CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->find($targetId) : null;
            if (! $course || ! $entitlements->isOfferable($tenant, $course)) {
                throw ValidationException::withMessages(['target' => 'Dieser Kurs wird von eurer Bootsschule aktuell nicht angeboten.']);
            }
        } elseif ($type === 'product') {
            if (! $targetId || ! Product::where('active', true)->whereKey($targetId)->exists()) {
                throw ValidationException::withMessages(['target' => 'Ungültiges Produkt.']);
            }
        } else {
            throw ValidationException::withMessages(['target' => 'Bitte einen Kurs oder ein Produkt wählen.']);
        }

        $codes = collect(preg_split('/[\s,;]+/', $validated['codes']))
            ->map(fn ($code) => strtoupper(trim($code)))
            ->filter()
            ->unique()
            ->values();

        $imported = 0;
        $duplicates = [];

        foreach ($codes as $code) {
            try {
                Coupon::create([
                    'code' => $code,
                    'course_id' => $type === 'course' ? $targetId : null,
                    'product_id' => $type === 'product' ? $targetId : null,
                    'tenant_id' => $tenant->id,
                    'batch_label' => 'Import vom '.now()->format('d.m.Y H:i'),
                    'created_by_user_id' => $request->user()->id,
                ]);
                $imported++;
            } catch (QueryException $e) {
                if ($e->getCode() === '23505') {
                    $duplicates[] = $code;

                    continue;
                }

                throw $e;
            }
        }

        $message = $imported === 1 ? '1 Code importiert.' : "{$imported} Codes importiert.";
        if ($duplicates !== []) {
            $shown = implode(', ', array_slice($duplicates, 0, 5));
            $message .= ' '.count($duplicates)." bereits vorhanden und übersprungen ({$shown}".(count($duplicates) > 5 ? ', …' : '').').';
        }

        return back()->with('status', $message);
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
}
