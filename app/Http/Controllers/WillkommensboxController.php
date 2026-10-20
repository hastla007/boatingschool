<?php

namespace App\Http\Controllers;

use App\Models\WillkommensboxOrder;
use App\Support\Countries;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bestellformular für die Willkommensbox (siehe WillkommensboxOrder) --
 * nur erreichbar, wenn der Nutzer "Lernplattform (inkl. Willkommensbox)"
 * eingelöst hat (siehe User::hasWillkommensboxAccess,
 * CouponRedemptionService). Genau wie beim Praxisformular ist die
 * Bestellung einmalig: nach dem Absenden zeigt die Seite nur noch eine
 * Bestätigung, kein erneutes Formular.
 */
class WillkommensboxController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($user->hasWillkommensboxAccess($tenant), 403, 'Die Willkommensbox ist für dich aktuell nicht freigeschaltet.');

        $order = WillkommensboxOrder::where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();

        if ($order) {
            return view('willkommensbox.index', ['state' => 'submitted', 'order' => $order]);
        }

        return view('willkommensbox.index', ['state' => 'form', 'user' => $user]);
    }

    public function store(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($user->hasWillkommensboxAccess($tenant), 403, 'Die Willkommensbox ist für dich aktuell nicht freigeschaltet.');

        $existing = WillkommensboxOrder::where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists();

        if ($existing) {
            return back()->with('status', 'Du hast deine Willkommensbox bereits bestellt.');
        }

        $validated = $request->validate([
            'street' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', Rule::in(Countries::OPTIONS)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        WillkommensboxOrder::create($validated + [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
        ]);

        return redirect()->route('willkommensbox.index');
    }
}
