<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\NewLoginDeviceNotification;
use App\Support\BestEffortMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Hinweis-Mail bei einer Anmeldung von einem bisher unbekannten Gerät --
 * nur für Konten mit erhöhten Rechten (Owner/Admin/Instructor einer Schule,
 * Superadmin), bei Web- und App-Anmeldung. Ein „Gerät“ ist die Kombination
 * aus Browser- bzw. App-Familie, Betriebssystem und gekürzter IP-Adresse
 * (/24 bzw. /48); gespeichert wird davon nur ein HMAC (known_login_device),
 * weder die volle IP noch der User-Agent. Bei der allerersten Anmeldung
 * (noch kein Gerät bekannt) wird nur gemerkt, nicht gemailt.
 */
class NewLoginDeviceNotifier
{
    public function handle(User $user, Request $request, ?string $appPlatform = null): void
    {
        if (! $this->watches($user)) {
            return;
        }

        $device = $appPlatform !== null || $request->is('api/*')
            ? ['browser' => 'Lern-App', 'os' => self::platformName($appPlatform) ?? self::describe($request->userAgent())['os']]
            : self::describe($request->userAgent());
        $prefix = self::ipPrefix($request->ip());
        $fingerprint = hash_hmac('sha256', implode('|', [$device['browser'], $device['os'], $prefix]), (string) config('app.key'));

        $now = now();
        $knownBefore = DB::table('known_login_device')->where('user_id', $user->id)->exists();
        $inserted = DB::table('known_login_device')->insertOrIgnore([
            'user_id' => $user->id,
            'fingerprint' => $fingerprint,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);

        if ($inserted === 0) {
            DB::table('known_login_device')->where('user_id', $user->id)->where('fingerprint', $fingerprint)->update(['last_seen_at' => $now]);

            return;
        }
        if (! $knownBefore) {
            return;
        }

        BestEffortMail::send(
            fn () => $user->notify(new NewLoginDeviceNotification($now, $device['browser'], $device['os'], self::maskIp($request->ip()))),
            "Hinweis neue Anmeldung {$user->id}"
        );
    }

    /** Team-Rollen einer Schule (aktive Mitgliedschaft) oder Superadmin. */
    private function watches(User $user): bool
    {
        if ($user->is_superadmin) {
            return true;
        }

        // Anmeldung läuft ohne Mandanten-Kontext: schulübergreifend über die Owner-Verbindung.
        return DB::connection('pgsql_admin')->table('tenant_user')->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'admin', 'instructor'])->where('status', 'active')->exists();
    }

    /** @return array{browser: string, os: string} grobe Familie, keine Versionsnummern */
    public static function describe(?string $userAgent): array
    {
        $ua = (string) $userAgent;
        $browser = match (true) {
            str_contains($ua, 'Edg/') || str_contains($ua, 'EdgA/') || str_contains($ua, 'EdgiOS/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS/') => 'Firefox',
            str_contains($ua, 'SamsungBrowser/') => 'Samsung Internet',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Unbekannter Browser',
        };
        $os = match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'unbekanntes System',
        };

        return ['browser' => $browser, 'os' => $os];
    }

    private static function platformName(?string $platform): ?string
    {
        return match ($platform) {
            'ios' => 'iOS',
            'android' => 'Android',
            default => null,
        };
    }

    /** Netzanteil der Adresse: IPv4 /24, IPv6 /48. */
    public static function ipPrefix(?string $ip): string
    {
        $packed = $ip ? @inet_pton($ip) : false;
        if ($packed === false) {
            return 'unbekannt';
        }

        return strlen($packed) === 4 ? bin2hex(substr($packed, 0, 3)) : bin2hex(substr($packed, 0, 6));
    }

    /** Für die Mail: letzter Block unkenntlich (203.0.113.x bzw. 2001:db8:1::/48). */
    public static function maskIp(?string $ip): string
    {
        $packed = $ip ? @inet_pton($ip) : false;
        if ($packed === false) {
            return 'unbekannt';
        }
        if (strlen($packed) === 4) {
            return implode('.', array_slice(explode('.', (string) inet_ntop($packed)), 0, 3)).'.x';
        }

        return inet_ntop(substr($packed, 0, 6).str_repeat("\0", 10)).'/48';
    }
}
