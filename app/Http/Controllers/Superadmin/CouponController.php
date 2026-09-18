<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CouponController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->only(['tenant_id', 'status']);

        $coupons = Coupon::with('course', 'product', 'tenant', 'redeemedBy', 'redeemedTenant')
            ->when($filters['tenant_id'] ?? null, fn ($q, $tenantId) => $q->where('tenant_id', $tenantId))
            ->when(($filters['status'] ?? null) === 'redeemed', fn ($q) => $q->whereNotNull('redeemed_at'))
            ->when(($filters['status'] ?? null) === 'open', fn ($q) => $q->whereNull('redeemed_at'))
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        return view('superadmin.coupons.index', [
            'coupons' => $coupons,
            'courses' => CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->orderBy('name')->get(),
            'products' => Product::where('active', true)->orderBy('name')->get(),
            'tenants' => Tenant::orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }

    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'target' => ['required', 'string'],
            'tenant_id' => ['nullable', 'uuid', 'exists:tenant,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:500'],
            'batch_label' => ['nullable', 'string', 'max:255'],
        ]);

        [$type, $targetId] = array_pad(explode(':', $validated['target'], 2), 2, null);

        if ($type === 'course') {
            if (! $targetId || ! CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->whereKey($targetId)->exists()) {
                throw ValidationException::withMessages(['target' => 'Ungültiger Kurs.']);
            }
        } elseif ($type === 'product') {
            if (! $targetId || ! Product::where('active', true)->whereKey($targetId)->exists()) {
                throw ValidationException::withMessages(['target' => 'Ungültiges Produkt.']);
            }
        } else {
            throw ValidationException::withMessages(['target' => 'Bitte einen Kurs oder ein Produkt wählen.']);
        }

        $quantity = $validated['quantity'];
        $batchLabel = ($validated['batch_label'] ?? null) ?: ($quantity > 1 ? 'Batch vom '.now()->format('d.m.Y H:i') : null);

        for ($i = 0; $i < $quantity; $i++) {
            Coupon::create([
                'code' => Coupon::generateUniqueCode(),
                'course_id' => $type === 'course' ? $targetId : null,
                'product_id' => $type === 'product' ? $targetId : null,
                'tenant_id' => $validated['tenant_id'] ?? null,
                'batch_label' => $batchLabel,
                'created_by_user_id' => $request->user()->id,
            ]);
        }

        $message = $quantity === 1 ? 'Gutschein-Code erzeugt.' : "{$quantity} Gutschein-Codes erzeugt.";

        return back()->with('status', $message);
    }
}
