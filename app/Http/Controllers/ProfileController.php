<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Entitlement;
use App\Models\ProductPurchase;
use App\Notifications\ConfirmPendingEmail;
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
     *
     * Eine geänderte E-Mail-Adresse wird nicht sofort übernommen, sondern
     * erst über confirmEmail() nach Klick auf den zugeschickten Link (siehe
     * Sicherheitsreview H2) -- sonst könnte jemand hier eine fremde Adresse
     * eintragen und sie sofort für sein Konto nutzen, ohne sie je besessen
     * zu haben.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $newEmail = $validated['email'];
        unset($validated['email']);

        $user->fill($validated);
        $user->display_name = trim($validated['first_name'].' '.$validated['last_name']);
        $user->save();

        $status = 'Profil gespeichert.';

        if (strcasecmp($newEmail, $user->email) !== 0) {
            $user->forceFill(['pending_email' => $newEmail])->save();
            $user->notify(new ConfirmPendingEmail($newEmail));

            $status = 'Profil gespeichert. Bitte bestätige deine neue E-Mail-Adresse über den Link, den wir dir geschickt haben -- bis dahin bleibt deine bisherige Adresse aktiv.';
        }

        // Menschenlesbarer Text statt eines reinen Status-Schlüssels, da die
        // generische Flash-Banner-Anzeige im Layout (siehe layouts/app.blade.php)
        // session('status') unverändert ausgibt -- das gilt insbesondere für
        // das return_to-Ziel, das keine eigene Übersetzung dafür hat.
        $returnTo = $request->session()->pull('profile.return_to');
        if ($returnTo && $this->isSafeReturnUrl($returnTo)) {
            return Redirect::to($returnTo)->with('status', $status);
        }

        return Redirect::route('profile.edit')->with('status', $status);
    }

    /** Übernimmt eine im Profil eingetragene neue E-Mail-Adresse, nachdem ihr Besitz über den zugeschickten Link bestätigt wurde. */
    public function confirmEmail(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ((string) $request->route('id') !== (string) $user->getKey()) {
            abort(403);
        }

        if (! $user->pending_email || ! hash_equals(sha1($user->pending_email), (string) $request->route('hash'))) {
            abort(403);
        }

        $user->forceFill([
            'email' => $user->pending_email,
            'pending_email' => null,
            'email_verified_at' => now(),
        ])->save();

        return Redirect::route('profile.edit')->with('status', 'Neue E-Mail-Adresse bestätigt.');
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
