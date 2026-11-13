<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Betriebsalarme per Mail: fehlgeschlagene geplante Aufgaben (Sicherung,
 * Go-live-Check) erreichen jemanden, auch ohne Sentry.
 */
class OpsAlert
{
    public static function recipient(): ?string
    {
        return config('app.ops_alert_email') ?: PlatformSetting::on('pgsql_admin')->value('support_email');
    }

    public static function forTask(string $task): Closure
    {
        return function () use ($task) {
            $to = self::recipient();
            if (! $to) {
                return;
            }
            BestEffortMail::send(fn () => Mail::raw(
                "Die geplante Aufgabe „{$task}“ ist am ".now('Europe/Berlin')->format('d.m.Y H:i')." fehlgeschlagen.\n\n"
                .'Bitte auf dem Server prüfen (storage/logs, ggf. Sentry) und die Aufgabe von Hand erneut ausführen.',
                fn ($m) => $m->to($to)->subject('Betriebsalarm: '.$task.' fehlgeschlagen')
            ), 'Betriebsalarm '.$task);
        };
    }

    /** Abgelaufene Zeilen des Datenbank-Caches löschen (nur beim Store „database“). */
    public static function pruneExpiredCache(): int
    {
        if (config('cache.default') !== 'database') {
            return 0;
        }
        $connection = config('cache.stores.database.connection');
        $table = config('cache.stores.database.table', 'cache');

        return DB::connection($connection)->table($table)->where('expiration', '<', now()->timestamp)->delete();
    }
}
