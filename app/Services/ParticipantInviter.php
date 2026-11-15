<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use App\Support\BestEffortMail;
use Illuminate\Database\Eloquent\Builder;
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
 * Bug-Review B31). Mit $queueMail (Sammel-Einladung) gehen die Mails über
 * die Queue statt im Request (Audit ARC-2); „mail_sent“ heißt dann
 * „eingereiht“.
 *
 * @phpstan-type Result array{type: 'existing'|'new', mail_sent: bool, user: ?User, duplicate?: bool}
 */
class ParticipantInviter
{
    /** Höchstens so viele Einladungen je Schule und Tag (einzeln und als Sammel-Einladung zusammen). */
    public const DAILY_LIMIT = 300;

    /**
     * Rückmeldung bei einer schon eingeladenen Person -- gleich, ob es zur
     * Adresse vorher ein Konto gab (Audit SEC-W-3).
     */
    public const ALREADY_INVITED_STATUS = 'Diese Person ist bereits eingeladen. Die Einladung wurde erneut verschickt, soweit das Tageslimit für diese Adresse es zulässt; der Link ist 7 Tage gültig.';

    /** Hinweis zu einem beim Einladen angegebenen Code (für beide Fälle gleich). */
    public const CODE_NOTE = ' Zum angegebenen Code: Ob er schon eingelöst ist, zeigt die Coupon-Codes-Verwaltung. Hatte die Person bereits ein Konto auf der Plattform, weisen Sie ihn dort nach Annahme der Einladung zu.';

    /** Offene Teilnehmer-Einladungen an bestehende Konten (7 Tage gültig), ohne schon Mitglied gewordene. */
    public static function openLearnerInvitations(Tenant $tenant): Builder
    {
        return TenantInvitation::where('tenant_id', $tenant->id)->where('role', 'learner')->whereNull('accepted_at')
            ->where('created_at', '>=', now()->subDays(7))
            ->whereRaw('not exists (select 1 from tenant_user tu join app_user au on au.id = tu.user_id
                where tu.tenant_id = tenant_invitation.tenant_id and lower(au.email) = lower(tenant_invitation.email))')
            ->orderByDesc('created_at')->orderBy('id');
    }

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
    public static function sendExistingAccountInvitation(User $user, Tenant $tenant, TenantInvitation $invitation, bool $queue = false): bool
    {
        if ($user->status === 'invited') {
            return BestEffortMail::send(fn () => app(AccountSetupLink::class)->send($user, $tenant, $invitation->role, $queue), "Einladung (Passwort festlegen) {$invitation->id}");
        }

        return BestEffortMail::send(function () use ($user, $invitation, $queue) {
            $notifiable = Notification::route('mail', $user->email);
            $notification = new TenantInvitationNotification($invitation);
            $queue ? $notifiable->notify($notification) : $notifiable->notifyNow($notification);
        }, "Einladungsmail Bestandskonto {$invitation->id}");
    }

    public function limitReached(Tenant $tenant): bool
    {
        return RateLimiter::tooManyAttempts(self::dailyKey($tenant), self::DAILY_LIMIT);
    }

    /**
     * @param  array{street?: ?string, postal_code?: ?string, city?: ?string, country?: ?string}  $address
     * @return array{type: 'existing'|'new', mail_sent: bool, user: ?User, duplicate?: bool, already_member?: bool, resent?: bool}
     */
    public function invite(Tenant $tenant, User $actor, string $name, string $email, array $address = [], bool $queueMail = false): array
    {
        RateLimiter::hit(self::dailyKey($tenant), 86400);
        $existingUser = User::on('pgsql_admin')->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();

        if ($existingUser) {
            $membership = TenantUser::on('pgsql_admin')->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $existingUser->id)->first();
            if ($membership) {
                // Schon in dieser Schule: noch nie angemeldet -> Link zum
                // Passwort-Festlegen erneut schicken (der alte ist evtl.
                // abgelaufen), sonst nichts verschicken.
                if ($existingUser->status !== 'invited') {
                    return ['type' => 'existing', 'mail_sent' => false, 'user' => null, 'already_member' => true, 'role' => $membership->role];
                }
                // Höchstens drei neue Links je Person und Tag (Schutz vor Mail-Flut).
                $resendKey = 'participant-invite-resend:'.$existingUser->id;
                if (RateLimiter::tooManyAttempts($resendKey, 3)) {
                    return ['type' => 'existing', 'mail_sent' => true, 'user' => null, 'duplicate' => true, 'resend_limit' => true];
                }
                RateLimiter::hit($resendKey, 86400);
                $mailSent = BestEffortMail::send(fn () => app(AccountSetupLink::class)->send($existingUser, $tenant, $membership->role, $queueMail), "Einladung erneut {$existingUser->id}");
                AuditLog::record($tenant->id, $actor->id, 'participant.invite_resent', 'app_user', $existingUser->id, null, ['email' => $existingUser->email]);

                return ['type' => 'existing', 'mail_sent' => $mailSent, 'user' => null, 'resent' => true];
            }

            // Schutz vor Mail-Bombing: dieselbe Adresse höchstens einmal am Tag
            // anschreiben; eine offene Einladung (Link 7 Tage gültig) wird
            // wiederverwendet statt jedes Mal eine neue anzulegen.
            $open = TenantInvitation::where('tenant_id', $tenant->id)->where('role', 'learner')->whereNull('accepted_at')
                ->whereRaw('lower(email) = ?', [mb_strtolower($existingUser->email)])->where('created_at', '>=', now()->subDays(7))
                ->latest('created_at')->first();
            // Review-Runde 9: je Adresse und Schule höchstens eine Mail am Tag
            // (auch an den Tagen 2–7 einer wiederverwendeten Einladung).
            if (! self::mayMailAddress($tenant, $existingUser->email)) {
                return ['type' => 'existing', 'mail_sent' => true, 'user' => null, 'duplicate' => true];
            }
            // Audit SEC-W-3: der Name erscheint in der Teilnehmerliste wie bei
            // einem neu angelegten Konto.
            $invitation = $open ?? TenantInvitation::create([
                'tenant_id' => $tenant->id,
                'email' => $existingUser->email,
                'name' => mb_substr($name, 0, 255),
                'role' => 'learner',
                'invited_by_user_id' => $actor->id,
            ]);

            $mailSent = self::sendExistingAccountInvitation($existingUser, $tenant, $invitation, $queueMail);

            AuditLog::record($tenant->id, $actor->id, 'participant.invite_pending', 'tenant_invitation', $invitation->id, null, [
                'email' => $existingUser->email,
            ]);

            // Wiederverwendete Einladung: dieselbe Rückmeldung wie beim
            // erneuten Senden an ein neu angelegtes Konto (kein Konto-Orakel).
            return ['type' => 'existing', 'mail_sent' => $mailSent, 'user' => null, 'resent' => $open !== null];
        }

        $user = DB::transaction(function () use ($tenant, $actor, $name, $email, $address) {
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

            AuditLog::record($tenant->id, $actor->id, 'participant.invite', 'app_user', $user->id, null, [
                'email' => $user->email,
            ]);

            return $user;
        });

        $mailSent = BestEffortMail::send(
            fn () => app(AccountSetupLink::class)->send($user, $tenant, 'learner', $queueMail),
            "Einladungsmail neuer Teilnehmer {$user->id}"
        );

        return ['type' => 'new', 'mail_sent' => $mailSent, 'user' => $user];
    }
}
