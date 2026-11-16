<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\MailPreferences;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * E-Mail-Benachrichtigungen (§ 7 UWG): Schalter im Profil und
 * Ein-Klick-Abmeldung aus der Mail über einen signierten Link ohne
 * Anmeldung (je Konto und Kategorie, siehe MailPreferences).
 */
class MailPreferenceController extends Controller
{
    public function update(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $request->validate(collect(MailPreferences::CATEGORIES)->mapWithKeys(fn ($label, $category) => [$category => ['sometimes', 'boolean']])->all());
        $wanted = collect(MailPreferences::CATEGORIES)->mapWithKeys(fn ($label, $category) => [$category => $request->boolean($category)])->all();

        $user = $request->user();
        $before = MailPreferences::forUser($user);
        MailPreferences::apply($user, $wanted)->save();
        AuditLog::record($tenantContext->id(), $user->id, 'profile.mail_preferences', 'app_user', $user->id, $before, $wanted);

        return redirect()->route('profile.edit')->with('status', 'mail-preferences-updated');
    }

    /**
     * Abmeldeseite aus dem Link in der Mail: erst bestätigen (Link-Scanner
     * von Mailprogrammen rufen Links per GET auf und sollen niemanden
     * abmelden). Die Ein-Klick-Abmeldung des Mailprogramms (RFC 8058)
     * kommt per POST an dieselbe Adresse.
     */
    public function show(Request $request, string $user, string $category): Response
    {
        $account = $this->account($user, $category);

        return response()->view('mail-preferences.unsubscribe', [
            'label' => MailPreferences::CATEGORIES[$category],
            'action' => $request->fullUrl(),
            'alreadyOff' => ! MailPreferences::wants($account, $category),
            'done' => false,
        ]);
    }

    public function unsubscribe(Request $request, string $user, string $category): Response
    {
        $account = $this->account($user, $category);

        if (MailPreferences::wants($account, $category)) {
            // Ohne Anmeldung und ggf. ohne Mandant: Konto über die
            // Owner-Verbindung ändern; die Signatur des Links belegt, dass
            // er aus einer Mail an genau dieses Konto stammt.
            MailPreferences::apply($account, [$category => false])->save();
            AuditLog::record(app(TenantContext::class)->id(), $account->id, 'mail.unsubscribe', 'app_user', $account->id, null, ['category' => $category]);
        }

        return response()->view('mail-preferences.unsubscribe', [
            'label' => MailPreferences::CATEGORIES[$category],
            'action' => null,
            'alreadyOff' => true,
            'done' => true,
        ]);
    }

    private function account(string $user, string $category): User
    {
        abort_unless(array_key_exists($category, MailPreferences::CATEGORIES) && Str::isUuid($user), 404);

        $account = User::on('pgsql_admin')->find($user);
        abort_unless($account, 404);

        return $account;
    }
}
