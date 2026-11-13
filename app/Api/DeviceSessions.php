<?php

namespace App\Api;

use App\Models\ApiDeviceSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gerätesitzungen der Mobile-API: kurzlebiges Access-Token, rotierendes
 * Refresh-Token (Wiederverwendung eines bereits rotierten Refresh-Tokens
 * widerruft die Sitzung). Token werden nur als SHA-256-Hash gespeichert und
 * genau einmal ausgegeben.
 */
class DeviceSessions
{
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array{session: ApiDeviceSession, access_token: string, refresh_token: string} */
    public function issue(User $user, string $deviceName, ?string $platform, ?string $appVersion): array
    {
        $access = 'bsa_'.Str::random(48);
        $refresh = 'bsr_'.Str::random(64);
        $now = now();

        $session = ApiDeviceSession::create([
            'user_id' => $user->id,
            'device_name' => mb_substr($deviceName, 0, 100),
            'device_platform' => $platform,
            'app_version' => $appVersion,
            'access_token_hash' => self::hash($access),
            'access_expires_at' => $now->copy()->addMinutes(config('mobile_api.access_ttl_minutes')),
            'refresh_token_hash' => self::hash($refresh),
            'refresh_expires_at' => $now->copy()->addDays(config('mobile_api.refresh_idle_days')),
            'max_expires_at' => $now->copy()->addDays(config('mobile_api.refresh_max_days')),
            'last_used_at' => $now,
            'created_at' => $now,
        ]);

        return ['session' => $session, 'access_token' => $access, 'refresh_token' => $refresh];
    }

    /**
     * Neues Token-Paar gegen ein gültiges Refresh-Token; null = ungültig.
     *
     * @return array{session: ApiDeviceSession, access_token: string, refresh_token: string}|null
     */
    public function refresh(string $refreshToken): ?array
    {
        $hash = self::hash($refreshToken);

        return DB::transaction(function () use ($hash) {
            $session = ApiDeviceSession::where('refresh_token_hash', $hash)->lockForUpdate()->first();
            $retry = false;
            if (! $session) {
                $previous = ApiDeviceSession::where('previous_refresh_token_hash', $hash)->whereNull('revoked_at')->lockForUpdate()->first();
                if (! $previous) {
                    return null;
                }
                // Dieselbe Erneuerung kurz danach noch einmal (Antwort im Funkloch
                // verloren, zwei parallele Anfragen): neues Paar statt Abmeldung.
                $grace = (int) config('mobile_api.refresh_reuse_grace_seconds', 60);
                if ($grace > 0 && $previous->refreshed_at && $previous->refreshed_at->gt(now()->subSeconds($grace))) {
                    $session = $previous;
                    $retry = true;
                } else {
                    // Später erneut benutzt: möglicher Diebstahl -- Sitzung beenden.
                    $previous->forceFill(['revoked_at' => now()])->save();

                    return null;
                }
            }
            if ($session->revoked_at || $session->refresh_expires_at->isPast() || $session->max_expires_at->isPast()
                || ($session->user?->status ?? null) !== 'active') {
                return null;
            }

            $access = 'bsa_'.Str::random(48);
            $refresh = 'bsr_'.Str::random(64);
            $now = now();
            $session->forceFill([
                'access_token_hash' => self::hash($access),
                'access_expires_at' => $now->copy()->addMinutes(config('mobile_api.access_ttl_minutes')),
                // Bei der Wiederholung bleibt das vorgelegte Token das „vorherige“
                // (die Toleranz läuft ab der ersten Erneuerung, nicht neu).
                'previous_refresh_token_hash' => $retry ? $session->previous_refresh_token_hash : $session->refresh_token_hash,
                'refresh_token_hash' => self::hash($refresh),
                'refresh_expires_at' => min($now->copy()->addDays(config('mobile_api.refresh_idle_days')), $session->max_expires_at),
                'last_used_at' => $now,
                'refreshed_at' => $retry ? $session->refreshed_at : $now,
            ])->save();

            return ['session' => $session, 'access_token' => $access, 'refresh_token' => $refresh];
        });
    }

    public function findByAccessToken(string $accessToken): ?ApiDeviceSession
    {
        return ApiDeviceSession::where('access_token_hash', self::hash($accessToken))
            ->whereNull('revoked_at')->where('access_expires_at', '>', now())->first();
    }

    public function revoke(ApiDeviceSession $session): void
    {
        $session->forceFill(['revoked_at' => now()])->save();
    }

    /** Alle App-Sitzungen eines Kontos beenden (Passwort-Reset/-Wechsel, Sperre, E-Mail-Wechsel). */
    public static function revokeAllFor(string $userId): void
    {
        ApiDeviceSession::where('user_id', $userId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /** @param  array{session: ApiDeviceSession, access_token: string, refresh_token: string}  $issued */
    public static function tokenPayload(array $issued): array
    {
        return [
            'access_token' => $issued['access_token'],
            'token_type' => 'Bearer',
            'access_expires_at' => $issued['session']->access_expires_at->toIso8601ZuluString(),
            'refresh_token' => $issued['refresh_token'],
            'refresh_expires_at' => $issued['session']->refresh_expires_at->toIso8601ZuluString(),
            'session_id' => $issued['session']->id,
        ];
    }
}
