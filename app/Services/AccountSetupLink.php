<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AccountInvitation;
use App\Support\CanonicalUrl;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Password;

/**
 * Link zum Festlegen des Passworts für ein neu eingeladenes Konto: 7 Tage
 * gültig (Passwort-Broker „invites“) und unter der Adresse der Schule
 * (Pfad-Modus /<slug>/, eigene Domain), damit die Person nach dem Festlegen
 * direkt in ihrer Schule landet.
 */
class AccountSetupLink
{
    public function url(User $user, ?Tenant $tenant): string
    {
        $token = Password::broker('invites')->createToken($user);

        return CanonicalUrl::root($tenant).route('password.reset', ['token' => $token], false).'?'.Arr::query([
            'email' => $user->email,
            'invite' => 1,
        ]);
    }

    public function send(User $user, ?Tenant $tenant, string $role): void
    {
        $user->notify(new AccountInvitation($tenant, $role, $this->url($user, $tenant)));
    }
}
