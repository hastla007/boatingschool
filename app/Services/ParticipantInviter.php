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
 *
 * @phpstan-type Result array{type: 'existing'|'new', mail_sent: bool, user: ?User, duplicate?: bool}
 */
class ParticipantInviter
{
    /** Höchstens so viele Einladungen je Schule und Tag (einzeln und als Sammel-Einladung zusammen). */
    public const DAILY_LIMIT = 300;

    public static function dailyKey(Tenant $tenant): string
    {
        return 'participant-invites:'.$tenant->id;
    }

    public function limitReached(Tenant $tenant): bool
    {
        return RateLimiter::tooManyAttempts(self::dailyKey($tenant), self::DAILY_LIMIT);
    }

    /**
     * @param  array{street?: ?string, postal_code?: ?string, city?: ?string, country?: ?string}  $address
     * @return array{type: 'existing'|'new', mail_sent: bool, user: ?User, duplicate?: bool, already_member?: bool, resent?: bool}
     */
    public function invite(Tenant $tenant, User $actor, string $name, string $email, array $address = []): array
    {
        RateLimiter::hit(self::dailyKey($tenant), 86400);
        $existingUser = User::on('pgsql_admin')->where('email', $email)->first();

        if ($existingUser) {
            $membership = TenantUser::on('pgsql_admin')->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $existingUser->id)->first();
            if ($membership) {
                // Schon in dieser Schule: noch nie angemeldet -> Link zum
                // Passwort-Festlegen erneut schicken (der alte ist evtl.
                // abgelaufen), sonst nichts verschicken.
                if ($existingUser->status !== 'invited') {
                    return ['type' => 'existing', 'mail_sent' => false, 'user' => null, 'already_member' => true];
                }
                // Höchstens drei neue Links je Person und Tag (Schutz vor Mail-Flut).
                $resendKey = 'participant-invite-resend:'.$existingUser->id;
                if (RateLimiter::tooManyAttempts($resendKey, 3)) {
                    return ['type' => 'existing', 'mail_sent' => true, 'user' => null, 'duplicate' => true];
                }
                RateLimiter::hit($resendKey, 86400);
                $mailSent = BestEffortMail::send(fn () => app(AccountSetupLink::class)->send($existingUser, $tenant, $membership->role), "Einladung erneut {$existingUser->id}");
                AuditLog::record($tenant->id, $actor->id, 'participant.invite_resent', 'app_user', $existingUser->id, null, ['email' => $existingUser->email]);

                return ['type' => 'existing', 'mail_sent' => $mailSent, 'user' => null, 'resent' => true];
            }

            // Schutz vor Mail-Bombing: dieselbe Adresse höchstens einmal am Tag
            // anschreiben; eine offene Einladung (Link 7 Tage gültig) wird
            // wiederverwendet statt jedes Mal eine neue anzulegen.
            $open = TenantInvitation::where('tenant_id', $tenant->id)->where('role', 'learner')->whereNull('accepted_at')
                ->whereRaw('lower(email) = ?', [mb_strtolower($existingUser->email)])->where('created_at', '>=', now()->subDays(7))
                ->latest('created_at')->first();
            if ($open && $open->created_at->gt(now()->subDay())) {
                return ['type' => 'existing', 'mail_sent' => true, 'user' => null, 'duplicate' => true];
            }
            $invitation = $open ?? TenantInvitation::create([
                'tenant_id' => $tenant->id,
                'email' => $existingUser->email,
                'role' => 'learner',
                'invited_by_user_id' => $actor->id,
            ]);

            $mailSent = BestEffortMail::send(
                fn () => Notification::route('mail', $existingUser->email)->notify(new TenantInvitationNotification($invitation)),
                "Einladungsmail Bestandskonto {$invitation->id}"
            );

            AuditLog::record($tenant->id, $actor->id, 'participant.invite_pending', 'tenant_invitation', $invitation->id, null, [
                'email' => $existingUser->email,
            ]);

            return ['type' => 'existing', 'mail_sent' => $mailSent, 'user' => null];
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
            fn () => app(AccountSetupLink::class)->send($user, $tenant, 'learner'),
            "Einladungsmail neuer Teilnehmer {$user->id}"
        );

        return ['type' => 'new', 'mail_sent' => $mailSent, 'user' => $user];
    }
}
