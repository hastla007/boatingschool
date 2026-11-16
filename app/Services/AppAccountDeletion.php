<?php

namespace App\Services;

use App\Models\ApiAccountDeletionRequest;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\PendingAppAccountDeletion;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Bestätigung einer in der App beantragten Kontolöschung über den Link aus
 * der Mail -- gemeinsam für die App (POST /api/v1/me/deletion-request/confirm)
 * und die Web-Bestätigungsseite (MOBILE_API_DELETION_CONFIRMATION_PATH).
 * Der Link ist der Nachweis: gelöscht wird immer das Konto aus dem Antrag,
 * unabhängig davon, wer gerade angemeldet ist.
 */
class AppAccountDeletion
{
    public function __construct(private TenantContext $tenantContext, private AccountErasureService $erasure) {}

    /** Pfad des Bestätigungslinks auf der zentralen Domain, z. B. "/app/konto-loeschen". */
    public static function confirmationPath(): string
    {
        $path = trim((string) config('mobile_api.deletion_confirmation_path'), '/');

        return '/'.($path !== '' ? $path : 'app/konto-loeschen');
    }

    /**
     * Zählt einen Prüfversuch; API und Web teilen sich die Kontingente je IP
     * und je Antrag. Liefert die Wartezeit in Sekunden, wenn das Limit
     * erreicht ist, sonst null.
     */
    public function throttle(string $ip, string $requestId): ?int
    {
        foreach (['api-deletion-confirm-ip:'.$ip => 20, 'api-deletion-confirm:'.$requestId => 10] as $limit => $max) {
            if (RateLimiter::tooManyAttempts($limit, $max)) {
                return RateLimiter::availableIn($limit);
            }
            RateLimiter::hit($limit, 3600);
        }

        return null;
    }

    /**
     * Offener, gültiger Antrag zu den Werten aus dem Link, sonst null --
     * ohne Unterscheidung, ob es ihn nie gab, er abgelaufen, schon benutzt
     * oder der Schlüssel falsch ist. Ändert nichts (sicher für GET).
     */
    public function find(mixed $requestId, mixed $confirmKey): ?PendingAppAccountDeletion
    {
        if (! is_string($requestId) || ! Str::isUuid($requestId) || ! is_string($confirmKey) || $confirmKey === '' || strlen($confirmKey) > 100) {
            return null;
        }

        // Ohne Anmeldung und Schulkontext: Antrag, Schule und Konto über die Owner-Verbindung suchen.
        $deletion = ApiAccountDeletionRequest::on('pgsql_admin')->withoutGlobalScopes()->whereKey($requestId)->first();
        if (! $deletion || $deletion->expires_at->isPast()
            || ! hash_equals((string) $deletion->confirm_key_hash, hash('sha256', $confirmKey))) {
            return null;
        }
        $tenant = Tenant::on('pgsql_admin')->find($deletion->tenant_id);
        $user = User::on('pgsql_admin')->find($deletion->user_id);
        // Nur eine (weiterhin) bestehende Teilnehmer-Mitgliedschaft; wer inzwischen zum
        // Team gehört, löscht im Web (dort u. a. die Prüfung „einziger Inhaber“).
        $isLearner = $tenant && $user && ! $user->is_superadmin && TenantUser::on('pgsql_admin')->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('role', 'learner')->exists();
        if (! $isLearner) {
            return null;
        }

        $hasOtherMemberships = TenantUser::on('pgsql_admin')->withoutGlobalScopes()
            ->where('user_id', $user->id)->where('tenant_id', '!=', $tenant->id)->exists();

        return new PendingAppAccountDeletion($deletion, $tenant, $user, ! $hasOtherMemberships);
    }

    /**
     * Löscht wie „Konto löschen“ im Web-Profil (AccountErasureService::
     * leaveTenant): Mitgliedschaft und Lerndaten bei der Schule, das Konto
     * samt aller Sitzungen, wenn keine weitere Mitgliedschaft bleibt.
     *
     * @return bool|null true = ganzes Konto gelöscht, false = nur bei dieser
     *                   Schule, null = Link inzwischen verbraucht/ersetzt
     */
    public function confirm(PendingAppAccountDeletion $pending, string $channel): ?bool
    {
        $deletion = $pending->deletion;
        $user = $pending->user;
        $previous = $this->tenantContext->tenant();

        $this->tenantContext->set(Tenant::find($pending->tenant->id) ?? $pending->tenant);
        try {
            // Den Antrag zuerst entfernen (nur mit dem geprüften Schlüssel): ein zweiter,
            // gleichzeitiger Aufruf mit demselben Link wartet auf diese Zeile und findet sie
            // danach nicht mehr, ein inzwischen neu ausgestellter Link bleibt unberührt.
            // Antrag und Löschung in EINER Transaktion: scheitert die Löschung, ist der
            // Antrag wieder da und der Link aus der Mail weiter gültig (sonst wäre er
            // verbraucht, ohne dass etwas gelöscht wurde).
            $done = DB::transaction(function () use ($deletion, $user, $pending, $channel) {
                if (ApiAccountDeletionRequest::whereKey($deletion->id)->where('confirm_key_hash', $deletion->confirm_key_hash)->delete() !== 1) {
                    return false;
                }
                $this->erasure->leaveTenant($user, $this->tenantContext->tenant());
                // Nachweis ohne Personenbezug (wie participant.remove im Admin-Bereich).
                AuditLog::record($pending->tenant->id, null, 'profile.delete', 'tenant_user', null, null, ['channel' => $channel]);

                return true;
            });
            if (! $done) {
                return null;
            }
        } finally {
            $previous ? $this->tenantContext->set($previous) : $this->tenantContext->clear();
        }

        return ! User::on('pgsql_admin')->whereKey($user->id)->exists();
    }
}
