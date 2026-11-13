<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use App\Support\BestEffortMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Eine Person als Teilnehmer einladen (Einzel- und Sammel-Einladung im
 * Bootsschul-Admin): bestehendes Konto → Einladung zum Bestätigen;
 * neue Adresse → Konto „invited“ plus Mitgliedschaft und Link zum
 * Passwort-Setzen. Ein Mailfehler bricht nichts ab (BestEffortMail,
 * Bug-Review B31).
 */
class ParticipantInviter
{
    /** Höchstens so viele Einladungen je Schule und Tag (einzeln und als Sammel-Einladung zusammen). */
    public const DAILY_LIMIT = 300;

    public static function dailyKey(Tenant $tenant): string
    {
        return 'participant-invites:'.$tenant->id;
    }

    /** Höchstens eine Einladungsmail je Adresse, Schule und Tag (zählt den Versuch mit). */
    public static function mayMailAddress(Tenant $tenant, string $email): bool
    {
        $key = 'invite-mail:'.$tenant->id.':'.sha1(mb_strtolower($email));
        if (RateLimiter::tooManyAttempts($key, 1)) {
            return false;
        }
        RateLimiter::hit($key, 86400);

        return true;
    }

    /**
     * Einladung eines bestehenden Kontos verschicken. Hat das Konto noch nie
     * ein Passwort festgelegt (status „invited“), kann es sich nicht anmelden
     * -- dann statt „melde dich an“ der Link zum Passwort-Festlegen in die
     * einladende Schule; die offene Einladung wird danach beim ersten Aufruf
     * angenommen (EnsureTenantMembership).
     */
    public static function sendExistingAccountInvitation(User $user, Tenant $tenant, TenantInvitation $invitation): bool
    {
        if ($user->status === 'invited') {
            return BestEffortMail::send(fn () => app(AccountSetupLink::class)->send($user, $tenant, $invitation->role), "Einladung (Passwort festlegen) {$invitation->id}");
        }

        return BestEffortMail::send(
            fn () => Notification::route('mail', $user->email)->notify(new TenantInvitationNotification($invitation)),
            "Einladungsmail Bestandskonto {$invitation->id}"
        );
    }

    public function limitReached(Tenant $tenant): bool
    {
        return RateLimiter::tooManyAttempts(self::dailyKey($tenant), self::DAILY_LIMIT);
    }

    /**
     * Offene Einladung dieser Rolle wiederverwenden oder anlegen (Link 7
     * Tage gültig, siehe TenantInvitation::scopeOpen). Gemeinsam für neue
     * Adressen und Bestandskonten: beide werden über die Einladung
     * angenommen (InvitationAcceptance), ein beim Einladen angegebener Code
     * ist bis dahin daran reserviert.
     */
    public static function openInvitation(Tenant $tenant, User $actor, string $email, string $role, ?string $name): TenantInvitation
    {
        return TenantInvitation::where('tenant_id', $tenant->id)->where('role', $role)->open()
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])->latest('created_at')->first()
            ?? TenantInvitation::create([
                'tenant_id' => $tenant->id,
                'email' => $email,
                'name' => $name,
                'role' => $role,
                'invited_by_user_id' => $actor->id,
            ]);
    }

    /**
     * Kein Konto-Orakel: für eine Adresse, die (noch) nicht aktives Mitglied
     * dieser Schule ist, verhält sich das Ergebnis nach außen gleich, egal ob
     * es plattformweit schon ein Konto gibt -- dieselbe Mail-Drossel (eine
     * Mail je Adresse, Schule und Tag), dieselben Felder (mail_sent,
     * duplicate) und in beiden Fällen eine offene Einladung, an der ein Code
     * bis zur Annahme reserviert wird. Die Unterscheidung „Konto angelegt“
     * (new) vs. „Einladung zum Annehmen“ (existing) bleibt nur intern.
     *
     * @param  array{street?: ?string, postal_code?: ?string, city?: ?string, country?: ?string}  $address
     * @return array{type: 'existing'|'new', mail_sent: bool, user: ?User, invitation?: TenantInvitation, duplicate?: bool, already_member?: bool, role?: string}
     */
    public function invite(Tenant $tenant, User $actor, string $name, string $email, array $address = []): array
    {
        RateLimiter::hit(self::dailyKey($tenant), 86400);
        $existingUser = User::on('pgsql_admin')->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();
        $membership = $existingUser
            ? TenantUser::on('pgsql_admin')->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $existingUser->id)->first()
            : null;

        // Schon aktiv in dieser Schule: nichts verschicken.
        if ($membership && $existingUser->status !== 'invited') {
            return ['type' => 'existing', 'mail_sent' => false, 'user' => null, 'already_member' => true, 'role' => $membership->role];
        }

        // Schutz vor Mail-Bombing und kein Zeit-Orakel: je Adresse und
        // Schule höchstens eine Mail am Tag, für neue Adressen genauso wie
        // für Bestandskonten (auch an den Tagen 2–7 einer offenen Einladung).
        if (! self::mayMailAddress($tenant, $existingUser?->email ?? $email)) {
            return ['type' => 'existing', 'mail_sent' => true, 'user' => null, 'duplicate' => true];
        }

        if ($existingUser) {
            // Der eingegebene Name wird nur an der Einladung gespeichert
            // (Anzeige in „Offene Einladungen“ wie bei einer neuen Adresse),
            // das bestehende Konto selbst bleibt unverändert.
            $invitation = self::openInvitation($tenant, $actor, $existingUser->email, $membership?->role ?? 'learner', $name);

            if ($membership) {
                // Von hier angelegt, aber noch nie angemeldet: Link zum
                // Passwort-Festlegen erneut schicken (der alte ist evtl.
                // abgelaufen).
                $mailSent = BestEffortMail::send(fn () => app(AccountSetupLink::class)->send($existingUser, $tenant, $membership->role), "Einladung erneut {$existingUser->id}");
                AuditLog::record($tenant->id, $actor->id, 'participant.invite_resent', 'app_user', $existingUser->id, null, ['email' => $existingUser->email]);
            } else {
                $mailSent = self::sendExistingAccountInvitation($existingUser, $tenant, $invitation);
                AuditLog::record($tenant->id, $actor->id, 'participant.invite_pending', 'tenant_invitation', $invitation->id, null, [
                    'email' => $existingUser->email,
                ]);
            }

            return ['type' => 'existing', 'mail_sent' => $mailSent, 'user' => null, 'invitation' => $invitation];
        }

        [$user, $invitation] = DB::transaction(function () use ($tenant, $actor, $name, $email, $address) {
            $user = User::create([
                'email' => $email,
                'display_name' => $name,
                'password' => Hash::make(Str::random(32)),
                'status' => 'invited',
                ...User::splitDisplayName($name),
            ]);

            TenantUser::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role' => 'learner',
                'status' => 'active',
                'street' => $address['street'] ?? null,
                'postal_code' => $address['postal_code'] ?? null,
                'city' => $address['city'] ?? null,
                'country' => $address['country'] ?? null,
            ]);

            // Auch das neue Konto nimmt über die Einladung an (beim
            // Festlegen des Passworts, InvitationAcceptance).
            $invitation = self::openInvitation($tenant, $actor, $user->email, 'learner', $name);

            AuditLog::record($tenant->id, $actor->id, 'participant.invite', 'app_user', $user->id, null, [
                'email' => $user->email,
            ]);

            return [$user, $invitation];
        });

        $mailSent = BestEffortMail::send(
            fn () => app(AccountSetupLink::class)->send($user, $tenant, 'learner'),
            "Einladungsmail neuer Teilnehmer {$user->id}"
        );

        return ['type' => 'new', 'mail_sent' => $mailSent, 'user' => $user, 'invitation' => $invitation];
    }
}
