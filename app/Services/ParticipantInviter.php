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
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Eine Person als Teilnehmer einladen (Einzel- und Sammel-Einladung im
 * Bootsschul-Admin): bestehendes Konto → Einladung zum Bestätigen;
 * neue Adresse → Konto „invited“ plus Mitgliedschaft und Link zum
 * Passwort-Setzen. Ein Mailfehler bricht nichts ab (BestEffortMail,
 * Bug-Review B31).
 *
 * @phpstan-type Result array{type: 'existing'|'new', mail_sent: bool, user: ?User}
 */
class ParticipantInviter
{
    /**
     * @param  array{street?: ?string, postal_code?: ?string, city?: ?string, country?: ?string}  $address
     * @return array{type: 'existing'|'new', mail_sent: bool, user: ?User}
     */
    public function invite(Tenant $tenant, User $actor, string $name, string $email, array $address = []): array
    {
        $existingUser = User::on('pgsql_admin')->where('email', $email)->first();

        if ($existingUser) {
            $invitation = TenantInvitation::create([
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
            fn () => Password::sendResetLink(['email' => $user->email]),
            "Einladungsmail neuer Teilnehmer {$user->id}"
        );

        return ['type' => 'new', 'mail_sent' => $mailSent, 'user' => $user];
    }
}
