<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Entitlement;
use App\Models\ProductPurchase;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request, TenantContext $tenantContext): View
    {
        $tenant = $tenantContext->tenant();

        $user = $request->user();

        // Kommt der Lerner über das "Profil vervollständigen"-Gate der
        // Praxisformular-Anmeldung hierher (siehe PraxisPruefungController),
        // merken wir uns die Rücksprungadresse für update(), damit er nach
        // dem Speichern direkt dort weitermachen kann.
        if ($request->filled('return_to') && $this->isSafeReturnUrl($request->string('return_to'))) {
            $request->session()->put('profile.return_to', (string) $request->string('return_to'));
        }

        // "Deine Buchungen" vereint Kurszugänge (Entitlement) und
        // freigeschaltete Zusatzprodukte (ProductPurchase) in einer Liste --
        // beides wird über denselben Coupon-Mechanismus freigeschaltet
        // (siehe CouponRedemptionService), nur jeweils unterschiedlich
        // abgelegt. source_reference trägt bei einem Coupon-Ursprung den
        // tatsächlich eingelösten Code.
        $entitlements = Entitlement::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->with('course')->orderByDesc('valid_from')->get()
            ->map(fn (Entitlement $e) => [
                'label' => $e->course->name ?? '—',
                'source_type' => $e->source_type,
                'source_reference' => $e->source_reference,
                'date' => $e->valid_from,
            ]);

        $productPurchases = ProductPurchase::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->with('product')->orderByDesc('created_at')->get()
            ->map(fn (ProductPurchase $p) => [
                'label' => $p->product->name ?? '—',
                'source_type' => $p->source_type,
                'source_reference' => $p->source_reference,
                'date' => $p->created_at,
            ]);

        $bookings = $entitlements->concat($productPurchases)->sortByDesc('date')->values();

        return view('profile.edit', [
            'user' => $user,
            'bookings' => $bookings,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $user->fill($validated);
        $user->display_name = trim($validated['first_name'].' '.$validated['last_name']);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($user->wasChanged('email')) {
            $user->sendEmailVerificationNotification();
        }

        // Menschenlesbarer Text statt eines reinen Status-Schlüssels, da die
        // generische Flash-Banner-Anzeige im Layout (siehe layouts/app.blade.php)
        // session('status') unverändert ausgibt -- das gilt insbesondere für
        // das return_to-Ziel, das keine eigene Übersetzung dafür hat.
        $returnTo = $request->session()->pull('profile.return_to');
        if ($returnTo && $this->isSafeReturnUrl($returnTo)) {
            return Redirect::to($returnTo)->with('status', 'Profil gespeichert.');
        }

        return Redirect::route('profile.edit')->with('status', 'Profil gespeichert.');
    }

    /** Nur serverrelative Pfade zulassen, kein Schema/Host (Open-Redirect-Schutz). */
    private function isSafeReturnUrl(string $url): bool
    {
        return str_starts_with($url, '/') && ! str_starts_with($url, '//') && ! str_contains($url, '://');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
