<?php

namespace App\Support;

use App\Console\Commands\BackupApplication;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VideoLesson;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Go-live-Check (app:check-production, Superadmin „Systemstatus“): prüft
 * Konfiguration und Betrieb auf typische Lücken vor bzw. nach dem Start.
 *
 * Ergebnis je Punkt: 'ok', 'warn' (sollte vor dem Start erledigt sein) oder
 * 'fail' (Betrieb ist so nicht sicher oder funktioniert nicht).
 */
class ProductionReadiness
{
    public const SCHEDULER_HEARTBEAT_KEY = 'ops:scheduler-heartbeat';

    /** @return list<array{group: string, label: string, status: string, detail: string}> */
    public static function checks(): array
    {
        $checks = [];
        $add = function (string $group, string $label, string $status, string $detail = '') use (&$checks) {
            $checks[] = compact('group', 'label', 'status', 'detail');
        };
        $safely = function (callable $check) use ($add) {
            try {
                $check();
            } catch (Throwable $e) {
                $add('System', 'Prüfung fehlgeschlagen', 'fail', Str::limit($e->getMessage(), 200));
            }
        };

        // --- Konfiguration ---------------------------------------------------
        $env = (string) config('app.env');
        $add('Konfiguration', 'APP_ENV = production', $env === 'production' ? 'ok' : 'fail', "aktuell: {$env}");
        $add('Konfiguration', 'APP_DEBUG aus', config('app.debug') ? 'fail' : 'ok', config('app.debug') ? 'Fehlerseiten zeigen sonst Code und Zugangsdaten' : '');
        $add('Konfiguration', 'APP_KEY gesetzt', config('app.key') ? 'ok' : 'fail');
        // Shop-Anbindung: kurze HMAC-Geheimnisse ließen sich aus einer mitgeschnittenen
        // Anfrage offline erraten -- damit könnte man Codes für jede Schule erzeugen.
        $shortSecrets = collect(array_merge(array_values((array) config('commerce.keys')), [config('commerce.outbound_secret')]))
            ->filter()->filter(fn ($secret) => strlen((string) $secret) < 32)->count();
        if (config('commerce.keys') || config('commerce.outbound_secret')) {
            $add('Konfiguration', 'Geheimnisse der Shop-Anbindung mind. 32 Zeichen', $shortSecrets === 0 ? 'ok' : 'fail', $shortSecrets ? "{$shortSecrets} zu kurz (z. B. openssl rand -hex 32)" : '');
        }
        $url = (string) config('app.url');
        $add('Konfiguration', 'APP_URL mit https', str_starts_with($url, 'https://') ? 'ok' : 'fail', $url);
        $central = (string) config('app.central_domain');
        $add('Konfiguration', 'CENTRAL_DOMAIN gesetzt', in_array($central, ['', 'localhost', '127.0.0.1'], true) ? 'fail' : 'ok', $central);
        $add('Konfiguration', 'Sichere Session-Cookies (SESSION_SECURE_COOKIE=true)', config('session.secure') ? 'ok' : 'warn');
        $add('Konfiguration', 'Cache nicht „array“', config('cache.default') === 'array' ? 'fail' : 'ok', (string) config('cache.default'));
        $add('Konfiguration', 'LOG_LEVEL nicht „debug“', config('logging.channels.'.config('logging.default').'.level') === 'debug' || config('logging.channels.daily.level') === 'debug' ? 'warn' : 'ok');
        $add('Konfiguration', 'Fehlertracking (SENTRY_LARAVEL_DSN)', config('sentry.dsn') ? 'ok' : 'warn', config('sentry.dsn') ? '' : 'ohne DSN werden Fehler nur ins Log geschrieben');

        // --- Mail ----------------------------------------------------------
        $mailer = (string) config('mail.default');
        $host = (string) config("mail.mailers.{$mailer}.host");
        $mailOk = ! in_array($mailer, ['log', 'array'], true) && ! in_array($host, ['127.0.0.1', 'localhost', 'mailpit', ''], true) || in_array($mailer, ['ses', 'postmark', 'resend', 'mailgun'], true);
        $add('Mail', 'Echter Mailversand', $mailOk ? 'ok' : 'fail', "Mailer: {$mailer}".($host ? ", Host: {$host}" : ''));
        $from = (string) config('mail.from.address');
        $add('Mail', 'Absenderadresse', $from && ! str_ends_with($from, '@example.com') ? 'ok' : 'fail', $from ?: 'nicht gesetzt');

        // --- Dateien -------------------------------------------------------
        $link = public_path('storage');
        $add('Dateien', 'Storage-Link (php artisan storage:link)', is_link($link) || is_dir($link) ? 'ok' : 'fail', is_link($link) || is_dir($link) ? '' : 'ohne Link fehlen Logos, Bilder und Kartenausschnitte');
        $add('Dateien', 'storage/ beschreibbar', is_writable(storage_path('framework')) && is_writable(storage_path('logs')) ? 'ok' : 'fail');

        // --- Hintergrunddienste --------------------------------------------
        $last = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);
        $add('Hintergrunddienste', 'Scheduler (Cron: schedule:run jede Minute)', $last && now()->timestamp - $last < 300 ? 'ok' : 'fail', $last ? 'zuletzt '.self::ago($last) : 'noch nie gelaufen');
        // Derzeit legt die App keine Queue-Jobs an (Mails laufen synchron,
        // siehe BestEffortMail). Geprüft wird deshalb nur, ob Jobs hängen
        // bleiben -- dann fehlt der Worker (queue:work).
        if (config('queue.default') === 'database') {
            $safely(function () use ($add) {
                $oldest = DB::connection('pgsql_admin')->table(config('queue.connections.database.table', 'jobs'))->min('created_at');
                $stuck = $oldest && now()->timestamp - (int) $oldest > 900;
                $add('Hintergrunddienste', 'Queue (keine hängenden Jobs)', $stuck ? 'fail' : 'ok', $stuck ? 'Jobs warten seit über 15 Minuten: Worker (queue:work) läuft nicht' : '');
            });
        }
        $safely(function () use ($add) {
            $failed = DB::connection('pgsql_admin')->table(config('queue.failed.table', 'failed_jobs'))->count();
            $add('Hintergrunddienste', 'Fehlgeschlagene Jobs', $failed === 0 ? 'ok' : 'warn', $failed ? "{$failed} Job(s), siehe php artisan queue:failed" : '');
        });
        $backup = BackupApplication::latestBackupAt();
        $maxAge = (int) config('backup.max_age_hours', 26);
        $add('Hintergrunddienste', 'Datensicherung (app:backup)', $backup && $backup->diffInHours(now()) < $maxAge ? 'ok' : 'fail', $backup ? 'zuletzt '.$backup->format('d.m.Y H:i').(config('backup.disk') ? ', extern: '.config('backup.disk') : ', nur lokal') : 'noch keine Sicherung');
        if ($backup && ! config('backup.disk')) {
            $add('Hintergrunddienste', 'Sicherung außerhalb des Servers (BACKUP_DISK)', 'warn', 'fällt der Server aus, sind lokale Sicherungen mit weg');
        }

        // --- Datenbank -----------------------------------------------------
        $safely(function () use ($add) {
            $app = config('database.connections.pgsql.username');
            $owner = config('database.connections.pgsql_admin.username');
            $add('Datenbank', 'Getrennte Rollen für App und Owner', $app !== $owner ? 'ok' : 'fail', "App: {$app}, Owner: {$owner}");
            $role = DB::connection('pgsql_admin')->selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = ?', [$app]);
            $roleOk = $role && ! $role->rolsuper && ! $role->rolbypassrls;
            $add('Datenbank', 'App-Rolle ohne Superuser/BYPASSRLS', $roleOk ? 'ok' : 'fail', $roleOk ? '' : 'sonst greift die Mandantentrennung (RLS) nicht');
            // Mitglied der Owner-Rolle = gilt als Tabelleneigentümer, RLS greift dann nicht.
            $member = (bool) DB::connection('pgsql_admin')->selectOne('SELECT pg_has_role(?, ?, \'USAGE\') AS m', [$app, $owner])?->m;
            $add('Datenbank', 'App-Rolle nicht Mitglied der Owner-Rolle', $member ? 'fail' : 'ok', $member ? 'sonst greift die Mandantentrennung (RLS) nicht' : '');
            $weak = collect([$app => config('database.connections.pgsql.password'), $owner => config('database.connections.pgsql_admin.password')])
                ->filter(fn ($pw, $user) => strlen((string) $pw) < 16 || $pw === $user)->keys();
            $add('Datenbank', 'Starke DB-Passwörter (≥ 16 Zeichen)', $weak->isEmpty() ? 'ok' : 'fail', $weak->isEmpty() ? '' : 'zu schwach: '.$weak->implode(', '));
        });

        // --- Inhalte und Konten --------------------------------------------
        $safely(function () use ($add) {
            $superadmins = User::on('pgsql_admin')->where('is_superadmin', true)->get();
            $add('Konten', 'Superadmin vorhanden', $superadmins->isNotEmpty() ? 'ok' : 'fail');
            $without2fa = $superadmins->reject(fn (User $u) => $u->hasTwoFactor())->count();
            $add('Konten', 'Superadmins mit Zwei-Faktor-Anmeldung', $without2fa === 0 ? 'ok' : 'warn', $without2fa ? "{$without2fa} ohne 2FA" : '');

            $placeholders = VideoLesson::on('pgsql_admin')->where('video_url', VideoLesson::PLACEHOLDER_URL)->count();
            // Lernende sähen sonst ein Beispielvideo statt der Lektion.
            $add('Inhalte', 'Videolektionen mit echtem Video', $placeholders === 0 ? 'ok' : 'fail', $placeholders ? "{$placeholders} Lektion(en) noch mit Platzhaltervideo" : '');

            $incomplete = Tenant::on('pgsql_admin')->whereIn('status', ['active', 'trial'])->with('branding')->get()
                ->filter(fn (Tenant $t) => ! $t->branding || LegalPages::missingImprintFields($t->branding) !== [])
                ->pluck('name');
            $add('Inhalte', 'Impressumsangaben aller aktiven Schulen', $incomplete->isEmpty() ? 'ok' : 'warn', $incomplete->isEmpty() ? '' : 'unvollständig: '.$incomplete->take(5)->implode(', ').($incomplete->count() > 5 ? ' …' : ''));

            $footer = @file_get_contents(resource_path('views/components/site-footer.blade.php')) ?: '';
            // Sichtbar auf jeder Seite jeder Schule: vor dem Start zwingend ersetzen.
            $add('Inhalte', 'Footer ohne Platzhalter', str_contains($footer, 'XXX') ? 'fail' : 'ok', str_contains($footer, 'XXX') ? 'Footer enthält noch „XXX“' : '');
        });

        return $checks;
    }

    private static function ago(int $timestamp): string
    {
        return Carbon::createFromTimestamp($timestamp)->locale('de')->diffForHumans();
    }
}
