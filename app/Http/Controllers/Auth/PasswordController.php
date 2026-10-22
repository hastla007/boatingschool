<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        // Beendet alle anderen laufenden Sitzungen dieses Kontos (z. B. eines
        // Angreifers mit gestohlenen Zugangsdaten) -- die eigene, gerade
        // genutzte Sitzung bleibt bewusst aktiv (Sicherheitsreview N6, analog
        // zu NewPasswordController aus H2, das die eigene Sitzung aber nicht
        // ausschließen muss, da dort noch kein Login besteht).
        DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        return back()->with('status', 'password-updated');
    }
}
