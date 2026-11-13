<?php

namespace App\Services;

use App\Jobs\SendInvitationMails;
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

    /**
     * Nur mit queueMails(): gesammelte Einladungsmails, die dispatchQueuedMails()
     * als Queue-Jobs verschickt (Sammel-Einladung).
     *
     * @var list<array{type: 'setup'|'invitation', user_id: string, role?: string, invitation_id?: string}>
     */
    private array $pendingMails = [];

    private bool $queueMails = false;

    /**
     * Sammel-Einladung: Mails nicht sofort im Request verschicken, sondern
     * sammeln und danach per dispatchQueuedMails() als Jobs (je Paket)
     * an den Queue-Worker geben. mail_sent ist dann immer true (der
     * Versand steht zu dem Zeitpunkt noch aus).
     */
    public function queueMails(): static
    {
        $this->queueMails = true;

        return $this;
    }

    /** Gesammelte Einladungsmails als Jobs verschicken (und wieder direkt senden); liefert die Anzahl der Mails. */
    public function dispatchQueuedMails(): int
    {
        $count = count($this->pendingMails);
        foreach (array_chunk($this->pendingMails, SendInvitationMails::CHUNK) as $chunk) {
            SendInvitationMails::dispatch($chunk);
        }
        $this->pendingMails = [];
        $this->queueMails = false;

        return $count;
    }

    /** Sofort senden oder (Sammel-Einladung) für den Queue-Job vormerken. */
    private function sendOrQueue(array $mail, callable $sendNow): bool
    {
        if ($this->queueMails) {
            $this->pendingMails[] = $mail;

            return true;
        }

        return $sendNow();
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
     * Kein Konto-Orakel: für eine Adresse, die (noch) nicht Mitglied dieser
     * Schule ist, verhält sich das Ergebnis nach außen gleich, egal ob es
     * plattformweit schon ein Konto gibt -- dieselbe Mail-Drossel (eine Mail
     * je Adresse, Schule und Tag, auch beim allerersten Versand an eine neue
     * Adresse und beim erneuten Versand an ein nie aktiviertes Konto), und
     * der Aufrufer bekommt dieselben Felder (mail_sent, duplicate). Die
     * Unterscheidung „Mitglied angelegt“ (user) vs. „Einladung zum Annehmen“
     * (invitation) bleibt nur intern, z. B. für die Code-Zuweisung.
     *
     * @param  array{street?: ?string, postal_code?: ?string, city?: ?string, country?: ?string}  $address
     * @return array{type: 'existing'|'new', mail_sent: bool, user: ?User, invitation?: TenantInvitation, duplicate?: bool, already_member?: bool, role?: string}
     */
    public function invite(Tenant $tenant, User $actor, string $name, string $email, array $address = []): array
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
                // Dieselbe Drossel wie bei Bestandskonten (eine Mail je
                // Adresse, Schule und Tag) -- sonst verriete schon der
                // zweite Versuch am selben Tag, ob die Adresse beim ersten
                // Mal neu war (neu: „erneut verschickt“, Bestandskonto:
                // „heute schon eingeladen“).
                if (! self::mayMailAddress($tenant, $existingUser->email)) {
                    return ['type' => 'existing', 'mail_sent' => true, 'user' => null, 'duplicate' => true];
                }
                $mailSent = $this->sendOrQueue(
                    ['type' => 'setup', 'user_id' => $existingUser->id, 'role' => $membership->role],
                    fn () => BestEffortMail::send(fn () => app(AccountSetupLink::class)->send($existingUser, $tenant, $membership->role), "Einladung erneut {$existingUser->id}")
                );
                AuditLog::record($tenant->id, $actor->id, 'participant.invite_resent', 'app_user', $existingUser->id, null, ['email' => $existingUser->email]);

                return ['type' => 'existing', 'mail_sent' => $mailSent, 'user' => $membership->role === 'learner' ? $existingUser : null];
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
            // Der eingegebene Name wird nur an der Einladung gespeichert
            // (Anzeige in „Offene Einladungen“ wie bei einer neuen Adresse),
            // das bestehende Konto selbst bleibt unverändert.
            $invitation = $open ?? TenantInvitation::create([
                'tenant_id' => $tenant->id,
                'email' => $existingUser->email,
                'name' => $name,
                'role' => 'learner',
                'invited_by_user_id' => $actor->id,
            ]);

            $mailSent = $this->sendOrQueue(
                ['type' => 'invitation', 'user_id' => $existingUser->id, 'invitation_id' => $invitation->id],
                fn () => self::sendExistingAccountInvitation($existingUser, $tenant, $invitation)
            );

            AuditLog::record($tenant->id, $actor->id, 'participant.invite_pending', 'tenant_invitation', $invitation->id, null, [
                'email' => $existingUser->email,
            ]);

            return ['type' => 'existing', 'mail_sent' => $mailSent, 'user' => null, 'invitation' => $invitation];
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

        // Zählt für die Tagesdrossel je Adresse mit (siehe oben).
        self::mayMailAddress($tenant, $user->email);
        $mailSent = $this->sendOrQueue(
            ['type' => 'setup', 'user_id' => $user->id, 'role' => 'learner'],
            fn () => BestEffortMail::send(fn () => app(AccountSetupLink::class)->send($user, $tenant, 'learner'), "Einladungsmail neuer Teilnehmer {$user->id}")
        );

        return ['type' => 'new', 'mail_sent' => $mailSent, 'user' => $user];
    }
}
