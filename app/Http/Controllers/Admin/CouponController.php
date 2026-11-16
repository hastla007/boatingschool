<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CouponRedemptionService;
use App\Services\InvitationCoupon;
use App\Support\InstructorScope;
use App\Support\ParticipantFilters;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zwei Wege, wie eine Bootsschule an Zugangscodes für ihre eigenen
 * angebotenen Kurse/Produkte kommt: der Superadmin teilt direkt welche zu
 * (Superadmin\CouponController::store mit tenant_id), oder die Bootsschule
 * übernimmt selbst Codes in ihren Bestand (import()), die vorher im System
 * erzeugt (Kurs/Produkt liegt also schon fest) und erst danach über einen
 * externen Webshop verkauft wurden -- welchen Kurs/welches Produkt ein
 * importierter Code freischaltet, muss die Bootsschule deshalb nicht
 * angeben: das steht schon auf dem bestehenden Coupon-Datensatz, den
 * import() nur noch der eigenen tenant_id zuordnet. Aus dem so
 * entstandenen Bestand kann die Bootsschule wiederum einem bereits
 * registrierten Nutzer direkt einen freien Code zuweisen (assign()) --
 * das Gegenstück zum Self-Service-Einlösen durch den Nutzer selbst
 * (CouponRedeemController).
 */
class CouponController extends Controller
{
    private const NOT_A_MEMBER = 'Diese Person gehört nicht zu Ihrer Bootsschule.';

    public function import(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->tenant();

        $validated = $request->validate([
            // max passend zu max. 500 Codes à 20 Zeichen inkl. Trennzeichen
            // (Sicherheitsreview M8) -- verhindert, dass ein einzelner
            // Request beliebig viele Codes gegen den Bestand prüft.
            'codes' => ['required', 'string', 'max:12000'],
        ]);

        $codes = collect(preg_split('/[\s,;]+/', $validated['codes']))
            ->map(fn ($code) => strtoupper(trim($code)))
            ->filter()
            ->unique()
            ->values();

        abort_if($codes->count() > 500, 422, 'Bitte maximal 500 Codes pro Übernahme einreichen.');

        $claimed = 0;
        $alreadyOwned = 0;
        $redeemed = [];
        $unavailable = [];

        foreach ($codes as $code) {
            // Die RLS-Policy auf "coupon" lässt uns nur Codes sehen, die noch
            // niemandem zugeordnet sind (tenant_id NULL) oder uns bereits
            // gehören -- ein Code einer anderen Bootsschule sieht für uns
            // exakt wie ein unbekannter Code aus (siehe CouponRedeemController).
            // Gleiche Schreibweisen wie beim Einlösen (ohne Bindestriche usw.).
            $coupon = Coupon::lookup($code);

            if (! $coupon) {
                $unavailable[] = $code;

                continue;
            }

            if ($coupon->isRedeemed()) {
                $redeemed[] = $code;

                continue;
            }

            // Gesperrte oder abgelaufene Codes gelten als nicht verfügbar.
            if (! $coupon->isUsable()) {
                $unavailable[] = $code;

                continue;
            }

            if ($coupon->tenant_id === $tenant->id) {
                $alreadyOwned++;

                continue;
            }

            // Atomarer Claim statt lesen-dann-schreiben: WHERE tenant_id IS
            // NULL verhindert, dass zwei Bootsschulen denselben noch
            // unzugeordneten Code gleichzeitig übernehmen (Sicherheitsreview
            // H1). Gewinnt die andere das Rennen, betrifft dieses UPDATE 0
            // Zeilen -- der Code gilt für uns dann als nicht verfügbar.
            $claimedRows = Coupon::where('id', $coupon->id)->whereNull('tenant_id')->update(['tenant_id' => $tenant->id]);

            if ($claimedRows === 1) {
                $claimed++;
            } else {
                $unavailable[] = $code;
            }
        }

        $parts = [$claimed === 1 ? '1 Code übernommen.' : "{$claimed} Codes übernommen."];

        if ($alreadyOwned > 0) {
            $parts[] = "{$alreadyOwned} bereits im eigenen Bestand.";
        }
        if ($redeemed !== []) {
            $parts[] = count($redeemed).' bereits eingelöst: '.$this->shorten($redeemed).'.';
        }
        if ($unavailable !== []) {
            $parts[] = count($unavailable).' unbekannt oder nicht verfügbar: '.$this->shorten($unavailable).'.';
        }

        return back()->with('status', implode(' ', $parts));
    }

    /**
     * Code einem Teilnehmer zuweisen. Das Suchfeld „Zuweisen“ (mit
     * JavaScript) schickt die gewählte user_id; ohne JavaScript -- oder wenn
     * die Adresse nach der Auswahl geändert wurde -- löst der Server die
     * eingetippte E-Mail-Adresse auf, nur unter den Teilnehmern, denen die
     * Schule einen Code zuweisen kann (ParticipantFilters::assignableLearners(),
     * dieselbe Grenze wie die Suche Admin\ParticipantSearchController).
     * Fehler stehen unter „assign“; assign_coupon zeigt sie in der Zeile des
     * Codes an (admin/tabs/coupons.blade.php).
     */
    public function assign(Request $request, TenantContext $tenantContext, Coupon $coupon, CouponRedemptionService $redemption): Response
    {
        $tenant = $tenantContext->tenant();
        // Code einer anderen Schule (bzw. ein freier Code): wie unbekannt.
        abort_unless($coupon->tenant_id === $tenant->id, 404);

        $userId = is_string($request->input('user_id')) && $request->input('user_id') !== '' ? $request->input('user_id') : null;
        $email = is_string($request->input('participant_email')) ? mb_strtolower(trim($request->input('participant_email'))) : '';
        $fail = fn (string $message) => back()
            ->withInput(['assign_coupon' => $coupon->id, 'user_id' => (string) $userId, 'participant_email' => mb_substr($email, 0, 255)])
            ->withErrors(['assign' => $message]);

        // Eigener, nicht mehr einlösbarer Code (z. B. Doppelklick auf
        // „Zuweisen“ oder inzwischen gesperrt/abgelaufen): zurück zur
        // Codeliste mit Meldung statt 404. „Eingelöst“ wie
        // CouponRedemptionService, wenn zwei Anfragen gleichzeitig kommen.
        $unusable = match (true) {
            $coupon->isRedeemed() => 'Dieser Code wurde bereits eingelöst.',
            $coupon->isVoided() => 'Dieser Code ist gesperrt.',
            $coupon->isExpired() => 'Dieser Code ist abgelaufen.',
            default => null,
        };
        if ($unusable !== null) {
            return $fail($unusable);
        }

        if (($userId !== null && ! Str::isUuid($userId)) || mb_strlen($email) > 255) {
            return $fail(self::NOT_A_MEMBER);
        }

        // Für eine offene Einladung reserviert: wird beim Annehmen eingelöst.
        if (InvitationCoupon::reservedCodes($tenant, [$coupon->code]) !== []) {
            return $fail('Dieser Code ist für eine offene Einladung reserviert und wird eingelöst, sobald die Person sie annimmt.');
        }

        // Die gewählte Person gilt, solange die E-Mail-Adresse im Feld noch ihre ist.
        if ($userId === null || ($email !== '' && mb_strtolower((string) User::whereKey($userId)->value('email')) !== $email)) {
            if ($email === '') {
                return $fail('Bitte einen Teilnehmer wählen.');
            }
            $userId = ParticipantFilters::assignableLearners($tenant, $request->user())->whereRaw('lower(email) = ?', [$email])->value('id');
            if ($userId === null) {
                return $fail('Unter dieser E-Mail-Adresse gibt es keinen aktiven Teilnehmer Ihrer Bootsschule.');
            }
        }

        $membership = TenantUser::with('user')->where('user_id', $userId)->first();

        // Ausbilder mit Kurszuordnung: fremde Lernende wie Nicht-Mitglieder
        // behandeln (die Route ist ohnehin Inhabern/Admins vorbehalten).
        if (! $membership || ! InstructorScope::canSeeLearner($tenant, $request->user(), $userId)) {
            // Bug-Review (Du/Sie im Admin-Bereich -- weitere Reste): dieselbe
            // Meldung wie in EntitlementController nutzte hier noch die
            // informelle ihr-Form ("eurer" statt "Ihrer").
            return $fail(self::NOT_A_MEMBER);
        }

        // Review: keinen Code an ein gesperrtes bzw. beendetes Mitglied verbrauchen.
        if ($membership->status !== 'active') {
            return $fail('Dieses Mitglied ist nicht aktiv – ein Code würde ungenutzt verbraucht.');
        }

        // Wie die Auswahl: nur Teilnehmer (Lernende), keine offene Einladung.
        if ($membership->role !== 'learner') {
            return $fail('Zugangscodes lassen sich nur Teilnehmern zuweisen, nicht dem Team der Bootsschule.');
        }
        if ($membership->user->status === 'invited') {
            return $fail('Diese Person hat ihre Einladung noch nicht angenommen. Weisen Sie den Code nach der Annahme zu.');
        }
        // Gesperrtes bzw. geschlossenes Konto (app_user.status): kann sich
        // nicht anmelden, der Code würde ungenutzt verbraucht.
        if ($membership->user->status !== 'active') {
            return $fail('Dieses Konto ist gesperrt oder geschlossen – ein Code würde ungenutzt verbraucht.');
        }

        try {
            $redemption->redeem($coupon, $tenant, $membership->user, $request->user());
        } catch (RuntimeException $e) {
            // Review: Datenbankfehler (QueryException ist auch eine
            // RuntimeException) nicht als Formularfehler mit SQL anzeigen.
            throw_if($e instanceof QueryException, $e);

            return $fail($e->getMessage());
        }

        return back()->with('status', 'Code "'.$coupon->code.'" wurde '.$membership->user->name.' zugewiesen.');
    }

    /** @param  array<int, string>  $codes */
    private function shorten(array $codes): string
    {
        return implode(', ', array_slice($codes, 0, 5)).(count($codes) > 5 ? ', …' : '');
    }
}
