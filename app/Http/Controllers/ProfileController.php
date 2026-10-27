<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Entitlement;
use App\Models\ProductPurchase;
use App\Models\TenantUser;
use App\Notifications\ConfirmPendingEmail;
use App\Notifications\EmailChangeRequested;
use App\Services\AccountErasureService;
use App\Services\PersonalDataExportService;
use App\Support\BestEffortMail;
use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
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

        // Sicherheitsreview S3: Adresse/Geburtsdatum liegen jetzt je
        // Mitgliedschaft auf tenant_user statt kontoweit auf app_user --
        // 'tenant.member' (routes/web.php) garantiert hier eine aktive
        // Mitgliedschaft für den aktuellen Mandanten.
        $membership = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();

        return view('profile.edit', [
            'user' => $user,
            'membership' => $membership,
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
    public function update(ProfileUpdateRequest $request, TenantContext $tenantContext): RedirectResponse
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();
        $validated = $request->validated();

        $newEmail = $validated['email'];
        unset($validated['email']);

        $emailChanged = strcasecmp($newEmail, $user->email) !== 0;

        // Bug-Review (E-Mail-Änderung ohne Passwortprüfung): eine laufende
        // Session allein reichte bisher, um die E-Mail-Adresse zu ändern --
        // genauso sensibel wie die Kontolöschung (destroy() unten), die das
        // aktuelle Passwort längst verlangt. Nur beim tatsächlichen Wechsel
        // der Adresse erneut abgefragt, nicht bei einem reinen Namens-/
        // Adress-Update.
        if ($emailChanged) {
            $request->validateWithBag('emailChange', [
                'current_password' => ['required', 'current_password'],
            ]);
        }

        // Sicherheitsreview S3: street/postal_code/city/country/birthdate
        // liegen jetzt je Mitgliedschaft auf tenant_user statt kontoweit auf
        // app_user (siehe Migration 000469) -- nur first_name/last_name/
        // phone bleiben Konto-Identität und landen weiter auf $user.
        $addressFields = array_intersect_key($validated, array_flip(['street', 'postal_code', 'city', 'country', 'birthdate']));
        $validated = array_diff_key($validated, $addressFields);

        $user->fill($validated);
        $user->display_name = trim($validated['first_name'].' '.$validated['last_name']);
        $user->save();

        if ($addressFields !== []) {
            TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->update($addressFields);
        }

        $status = 'Profil gespeichert.';

        if ($emailChanged) {
            $user->forceFill(['pending_email' => $newEmail])->save();

            // Bug-Review H2 (Folgefehler, kritisch): $user->notify(...)
            // schickt über den 'mail'-Kanal IMMER an die aktuelle
            // $user->email -- nicht an pending_email. Der Besitz der NEUEN
            // Adresse wurde dadurch nie geprüft: der Nutzer bekommt den
            // Bestätigungslink für eine x-beliebige, ihm fremde Adresse
            // selbst in sein eigenes (bereits verifiziertes) Postfach
            // zugeschickt und kann ihn sofort selbst anklicken -- genau das
            // Vorab-Übernehmen eines Kontos aus Sicherheitsreview H2, nur
            // über das Profil statt über die Registrierung. Notification::
            // route('mail', $newEmail) schickt stattdessen explizit an die
            // NEUE Adresse; nur wer sie tatsächlich besitzt, sieht den Link.
            //
            // Bug-Review B31: das Profil samt pending_email ist zu diesem
            // Zeitpunkt schon gespeichert -- ein SMTP-Fehler beim Versand
            // soll das nicht mehr als HTTP 500 melden, siehe BestEffortMail.
            $mailSent = BestEffortMail::send(
                fn () => Notification::route('mail', $newEmail)->notify(new ConfirmPendingEmail($user->id, $newEmail)),
                "Bestätigungsmail neue E-Mail-Adresse Nutzer {$user->id}"
            );

            // Bug-Review (E-Mail-Änderung ohne Hinweis an die alte Adresse):
            // $user->email ist an dieser Stelle noch die BISHERIGE Adresse
            // (erst confirmEmail() übernimmt pending_email) -- $user->notify()
            // geht also absichtlich genau an sie, nicht an $newEmail. Der
            // Versand ist best effort: ein SMTP-Fehler hier soll das
            // eigentliche Profil-Speichern (oben bereits erfolgt) nicht als
            // Fehler erscheinen lassen.
            BestEffortMail::send(
                fn () => $user->notify(new EmailChangeRequested($newEmail)),
                "Hinweis an alte E-Mail-Adresse Nutzer {$user->id}"
            );

            $status = $mailSent
                ? 'Profil gespeichert. Bitte bestätige deine neue E-Mail-Adresse über den Link, den wir dir geschickt haben -- bis dahin bleibt deine bisherige Adresse aktiv.'
                : 'Profil gespeichert. Die Bestätigungsmail für deine neue E-Mail-Adresse konnte aber nicht versendet werden -- bitte versuche es später erneut.';
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

        // Bug-Review R3-6: die Unique-Prüfung bei der Eingabe
        // (ProfileUpdateRequest) kann eine inzwischen anderweitig vergebene
        // Adresse nicht mehr ausschließen (Zeitfenster zwischen Eingabe und
        // Bestätigung des Links) -- der echte, plattformweite
        // UNIQUE-Constraint auf app_user.email greift dann hier, bisher
        // ungefangen als HTTP 500 statt einer verständlichen Meldung.
        try {
            // Bug-Review (Sessions überleben den E-Mail-Wechsel): weder
            // remember_token noch andere laufende Sitzungen wurden hier
            // bisher angerührt -- anders als beim Passwortwechsel
            // (PasswordController, Sicherheitsreview M1/N6) blieb eine
            // bereits kompromittierte Sitzung (oder deren "Angemeldet
            // bleiben"-Cookie) über den E-Mail-Wechsel hinweg einfach
            // weiter gültig. remember_token wird deshalb genauso mitgeändert,
            // und alle anderen Sitzungen dieses Kontos werden beendet; die
            // eigene, gerade genutzte Sitzung bleibt bewusst aktiv.
            $user->forceFill([
                'email' => $user->pending_email,
                'pending_email' => null,
                'email_verified_at' => now(),
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('sessions')
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        } catch (UniqueConstraintViolationException) {
            // fresh() statt $user direkt: forceFill() oben hat "email" im
            // Model bereits auf den fremden Wert gesetzt, obwohl der
            // Schreibversuch fehlschlug -- ein erneutes save() auf
            // demselben (weiterhin "dirty") Objekt würde denselben
            // Konflikt sofort nochmal auslösen.
            $user->fresh()->forceFill(['pending_email' => null])->save();

            return Redirect::route('profile.edit')->with('status', 'Diese E-Mail-Adresse wird inzwischen von einem anderen Konto verwendet. Bitte wähle eine andere Adresse.');
        }

        return Redirect::route('profile.edit')->with('status', 'Neue E-Mail-Adresse bestätigt.');
    }

    /**
     * DSGVO-Review D3: Auskunfts-/Mitnahmerecht (Art. 15/20) -- liefert die
     * bei der aktuellen Bootsschule gespeicherten personenbezogenen Daten
     * als strukturierte, maschinenlesbare JSON-Datei zum Download.
     */
    public function exportData(Request $request, TenantContext $tenantContext, PersonalDataExportService $export): Response
    {
        $tenant = $tenantContext->tenant();
        $data = $export->export($request->user(), $tenant);

        $filename = 'meine-daten-'.$tenant->slug.'-'.now()->format('Y-m-d').'.json';

        return response(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->header('Content-Type', 'application/json')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }

    /** Nur serverrelative Pfade zulassen, kein Schema/Host (Open-Redirect-Schutz). */
    private function isSafeReturnUrl(string $url): bool
    {
        return str_starts_with($url, '/') && ! str_starts_with($url, '//') && ! str_contains($url, '://');
    }

    /**
     * Entfernt die Mitgliedschaft (und alle mandantengebundenen
     * personenbezogenen Daten) bei der aktuellen Bootsschule. Das Konto
     * selbst wird nur gelöscht, wenn danach keine Mitgliedschaft bei
     * irgendeiner Bootsschule mehr übrig ist -- eine Löschung bei Schule A
     * darf nicht ungefragt auch Mitgliedschaft und Lerndaten bei Schule B
     * entfernen (DSGVO-Review D1: jede Bootsschule ist ein eigener
     * datenschutzrechtlich Verantwortlicher).
     */
    public function destroy(Request $request, TenantContext $tenantContext, AccountErasureService $erasure): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        $tenant = $tenantContext->tenant();

        Auth::logout();

        $erasure->leaveTenant($user, $tenant);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
