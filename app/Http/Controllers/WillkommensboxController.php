<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\WillkommensboxOrder;
use App\Services\CourseProgressService;
use App\Services\EntitlementService;
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
 * CouponRedemptionService) UND den bootsschulweiten Kursfortschritt
 * (siehe TenantBranding::willkommensbox_threshold_percent) erreicht hat.
 * Genau wie beim Praxisformular ist die Bestellung einmalig: nach dem
 * Absenden zeigt die Seite nur noch eine Bestätigung, kein erneutes
 * Formular.
 */
class WillkommensboxController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseProgressService $courseProgress): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($user->hasWillkommensboxAccess($tenant), 403, 'Die Willkommensbox ist für dich aktuell nicht freigeschaltet.');

        $percent = $this->progressPercent($tenant, $user, $entitlements, $courseProgress);
        $threshold = $tenant->branding->willkommensbox_threshold_percent;

        if ($percent < $threshold) {
            return view('willkommensbox.index', ['state' => 'locked', 'percent' => $percent, 'threshold' => $threshold]);
        }

        $order = WillkommensboxOrder::where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();

        if ($order) {
            return view('willkommensbox.index', ['state' => 'submitted', 'order' => $order]);
        }

        // Sicherheitsreview S3: Adresse-Vorbefüllung kommt jetzt aus der
        // Mitgliedschaft (tenant_user) dieses Mandanten statt kontoweit von
        // app_user -- siehe Migration 000469.
        $membership = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();

        return view('willkommensbox.index', ['state' => 'form', 'membership' => $membership]);
    }

    public function store(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseProgressService $courseProgress): Response
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($user->hasWillkommensboxAccess($tenant), 403, 'Die Willkommensbox ist für dich aktuell nicht freigeschaltet.');

        $percent = $this->progressPercent($tenant, $user, $entitlements, $courseProgress);
        $threshold = $tenant->branding->willkommensbox_threshold_percent;
        abort_unless($percent >= $threshold, 403, "Für die Bestellung der Willkommensbox werden mindestens {$threshold}% Kursfortschritt benötigt.");

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

    /**
     * Die Willkommensbox ist nicht an einen bestimmten Kurs gekoppelt --
     * bei mehreren aktiven Kurszugängen zählt (wie beim Dashboard-Chart
     * "Kursfortschritt in der Lernphase") der am weitesten fortgeschrittene.
     */
    private function progressPercent(Tenant $tenant, User $user, EntitlementService $entitlements, CourseProgressService $courseProgress): int
    {
        return $entitlements->activeCourses($tenant, $user)
            ->map(fn ($course) => $courseProgress->overallPercent($course, $tenant, $user))
            ->max() ?? 0;
    }
}
