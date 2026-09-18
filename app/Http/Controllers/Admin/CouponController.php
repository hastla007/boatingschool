<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Support\TenantContext;
use Illuminate\View\View;

/**
 * Nur-Lese-Ansicht für eine Bootsschule: welche ihr zugeordneten oder von
 * ihren eigenen Nutzern eingelösten Coupons es gibt, inkl. wer sie wann
 * eingelöst hat. Erzeugt werden Coupons ausschließlich vom Superadmin
 * (siehe Superadmin\CouponController) -- diese Seite dient nur der Einsicht.
 */
class CouponController extends Controller
{
    public function index(TenantContext $tenantContext): View
    {
        $tenant = $tenantContext->tenant();

        $coupons = Coupon::with('course', 'product', 'redeemedBy')
            ->where('tenant_id', $tenant->id)
            ->orWhere('redeemed_tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->paginate(50);

        return view('admin.coupons', ['coupons' => $coupons]);
    }
}
