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

    /** Ab so vielen Schulen empfiehlt der Check Redis statt der Datenbank für Cache/Sessions/Queue. */
    public const REDIS_RECOMMENDED_FROM_SCHOOLS = 20;

    /** Lebenszeichen des Queue-Workers (Event Looping, siehe AppServiceProvider). */
    public const WORKER_HEARTBEAT_KEY = 'ops:queue-worker-heartbeat';

    /** Länger darf ein fälliger Job nicht warten, sonst fehlt Worker-Kapazität. */
    public const MAX_JOB_WAIT_SECONDS = 600;

    private static int $lastWorkerBeat = 0;

    /** Vom Worker bei jedem Schleifendurchlauf aufgerufen, schreibt höchstens alle 30 s. */
    public static function recordWorkerHeartbeat(): void
    {
        $now = now()->timestamp;
        if ($now - self::$lastWorkerBeat < 30 && rescue(fn () => Cache::has(self::WORKER_HEARTBEAT_KEY), false, false)) {
            return;
        }
        self::$lastWorkerBeat = $now;
        rescue(fn () => Cache::forever(self::WORKER_HEARTBEAT_KEY, $now), report: false);
    }

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
        // Oberfläche, Validierungsmeldungen und Datumsangaben sind deutsch; mit
        // anderer Sprache erschienen englische Framework-Texte.
        $locale = (string) config('app.locale');
        $add('Konfiguration', 'APP_LOCALE = de', $locale === 'de' ? 'ok' : 'warn', $locale === 'de' ? '' : "aktuell: {$locale} – Framework-Texte erscheinen sonst nicht auf Deutsch");
        // Shop-Anbindung: kurze HMAC-Geheimnisse ließen sich aus einer mitgeschnittenen
        // Anfrage offline erraten -- damit könnte man Codes für jede Schule erzeugen.
        $shortSecrets = collect(array_merge(array_values((array) config('commerce.keys')), [config('commerce.outbound_secret')]))
            ->filter()->filter(fn ($secret) => strlen((string) $secret) < 32)->count();
        if (config('commerce.keys') || config('commerce.outbound_secret')) {
            $add('Konfiguration', 'Geheimnisse der Shop-Anbindung mind. 32 Zeichen', $shortSecrets === 0 ? 'ok' : 'fail', $shortSecrets ? "{$shortSecrets} zu kurz (z. B. openssl rand -hex 32)" : '');
        }
        // Über http gingen Kontaktdaten der Shopkonten unverschlüsselt an den Shop
        // (Ausgang App → Shop). localhost/127.0.0.1 nur für lokale Tests.
        $shopUrl = (string) config('commerce.shop_url');
        if ($shopUrl !== '') {
            $shopHost = (string) parse_url($shopUrl, PHP_URL_HOST);
            $local = in_array($shopHost, ['localhost', '127.0.0.1', '::1'], true);
            $add('Konfiguration', 'COMMERCE_SHOP_URL mit https', str_starts_with($shopUrl, 'https://') ? 'ok' : ($local ? 'warn' : 'fail'), $shopUrl);
        }
        $url = (string) config('app.url');
        $add('Konfiguration', 'APP_URL mit https', str_starts_with($url, 'https://') ? 'ok' : 'fail', $url);
        $central = (string) config('app.central_domain');
        $add('Konfiguration', 'CENTRAL_DOMAIN gesetzt', in_array($central, ['', 'localhost', '127.0.0.1'], true) ? 'fail' : 'ok', $central);
        // Über https ohne Secure-Flag ginge das Session-Cookie auch über unverschlüsseltes http mit.
        $add('Konfiguration', 'Sichere Session-Cookies (SESSION_SECURE_COOKIE=true)', config('session.secure') ? 'ok' : (str_starts_with($url, 'https://') ? 'fail' : 'warn'));
        // „*“ vertraut jedem Absender: X-Forwarded-For (IP-Limits, Protokolle) wäre frei fälschbar.
        $proxies = trim((string) config('app.trusted_proxies'));
        $add('Konfiguration', 'TRUSTED_PROXIES ohne „*“', in_array($proxies, ['*', '**'], true) ? 'warn' : 'ok', in_array($proxies, ['*', '**'], true) ? 'nur die Adressen des eigenen Proxys/Load-Balancers eintragen' : ($proxies === '' ? 'keinem Proxy vertraut' : $proxies));
        // Nur für app:create-superadmin beim ersten Einrichten gedacht, danach aus der .env entfernen.
        $add('Konfiguration', 'SUPERADMIN_PASSWORD nicht in der .env', config('app.superadmin_password') ? 'warn' : 'ok', config('app.superadmin_password') ? 'nach dem Anlegen des Superadmins entfernen' : '');
        $envFile = app()->environmentFilePath();
        if (is_file($envFile)) {
            $othersCanRead = (fileperms($envFile) & 0o007) !== 0;
            $add('Konfiguration', '.env nur für den Deploy-Benutzer lesbar', $othersCanRead ? 'warn' : 'ok', $othersCanRead ? 'chmod 600 .env (enthält APP_KEY und Zugangsdaten)' : '');
        }
        // Pfad-Modus: alle Schulen und der Superadmin teilen sich einen Ursprung (SEC-W-4).
        $superadminHost = (string) config('app.superadmin_host');
        if ($superadminHost === '' && config('app.tenant_url_mode') !== 'subdomain') {
            $add('Konfiguration', 'Superadmin getrennt von den Schulen (SUPERADMIN_HOST)', 'warn', 'im Pfad-Modus teilen Schulen und Superadmin einen Ursprung: SUPERADMIN_HOST setzen oder TENANT_URL_MODE=subdomain');
        } elseif ($superadminHost !== '' && config('session.domain')) {
            $add('Konfiguration', 'Superadmin getrennt von den Schulen (SUPERADMIN_HOST)', 'warn', 'SESSION_DOMAIN teilt das Session-Cookie über Hosts hinweg: leer lassen');
        } else {
            $add('Konfiguration', 'Superadmin getrennt von den Schulen (SUPERADMIN_HOST)', 'ok', $superadminHost ?: 'Subdomain-Modus');
        }
        $add('Konfiguration', 'Cache nicht „array“', config('cache.default') === 'array' ? 'fail' : 'ok', (string) config('cache.default'));
        $add('Konfiguration', 'LOG_LEVEL nicht „debug“', config('logging.channels.'.config('logging.default').'.level') === 'debug' || config('logging.channels.daily.level') === 'debug' ? 'warn' : 'ok');
        // Ohne Pflicht-2FA reicht für den Superadmin ein geleaktes Passwort (deploy/README.md §1).
        $add('Konfiguration', 'Zwei-Faktor-Pflicht im Superadmin (SUPERADMIN_REQUIRE_TWO_FACTOR)', config('auth.superadmin_two_factor') ? 'ok' : 'warn', config('auth.superadmin_two_factor') ? '' : 'abgeschaltet: SUPERADMIN_REQUIRE_TWO_FACTOR aus der .env entfernen oder auf true setzen');
        $add('Konfiguration', 'Fehlertracking (SENTRY_LARAVEL_DSN)', config('sentry.dsn') ? 'ok' : 'warn', config('sentry.dsn') ? '' : 'ohne DSN werden Fehler nur ins Log geschrieben');

        // --- Mail ----------------------------------------------------------
        $mailer = (string) config('mail.default');
        $host = (string) config("mail.mailers.{$mailer}.host");
        $add('Mail', 'Echter Mailversand', self::sendsRealMail($mailer) ? 'ok' : 'fail', "Mailer: {$mailer}".($host ? ", Host: {$host}" : '')
            .(in_array(config("mail.mailers.{$mailer}.transport"), ['failover', 'roundrobin'], true) ? ' ('.implode(', ', (array) config("mail.mailers.{$mailer}.mailers")).')' : ''));
        $from = (string) config('mail.from.address');
        $add('Mail', 'Absenderadresse', $from && ! str_ends_with($from, '@example.com') ? 'ok' : 'fail', $from ?: 'nicht gesetzt');

        // --- Dateien -------------------------------------------------------
        $link = public_path('storage');
        $add('Dateien', 'Storage-Link (php artisan storage:link)', is_link($link) || is_dir($link) ? 'ok' : 'fail', is_link($link) || is_dir($link) ? '' : 'ohne Link fehlen Logos und hochgeladene Bilder');
        // Vite-Build (laravel-vite-plugin 3: public/build/manifest.json). Ohne Manifest
        // bricht jede Seite mit 500 ab; eine liegengebliebene public/hot aus
        // „npm run dev“ lädt CSS/JS vom Entwicklungsserver, der produktiv nicht läuft.
        $manifest = is_file(public_path('build/manifest.json'));
        $hot = is_file(public_path('hot'));
        $add('Dateien', 'Frontend-Build (npm run build)', $manifest && ! $hot ? 'ok' : 'fail',
            ! $manifest ? 'public/build/manifest.json fehlt: npm ci && npm run build' : ($hot ? 'public/hot vom Vite-Entwicklungsserver löschen' : ''));
        $add('Dateien', 'storage/ beschreibbar', is_writable(storage_path('framework')) && is_writable(storage_path('logs')) ? 'ok' : 'fail');

        // --- Hintergrunddienste --------------------------------------------
        $last = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);
        $add('Hintergrunddienste', 'Scheduler (Cron: schedule:run jede Minute)', $last && now()->timestamp - $last < 300 ? 'ok' : 'fail', $last ? 'zuletzt '.self::ago($last) : 'noch nie gelaufen');
        // Steht der Scheduler (oder der Server), läuft auch dieser Check nicht mehr: nur ein externer Dienst merkt das.
        $add('Hintergrunddienste', 'Externer Totmann-Schalter (OPS_HEARTBEAT_URL)', config('app.ops_heartbeat_url') ? 'ok' : 'warn', config('app.ops_heartbeat_url') ? '' : 'ohne externen Heartbeat-Dienst fällt ein stehender Scheduler niemandem auf');
        $safely(function () use ($add) {
            $to = OpsAlert::recipient();
            $add('Hintergrunddienste', 'Empfänger für Betriebsalarme', $to ? 'ok' : 'fail', $to ?: 'OPS_ALERT_EMAIL oder Support-Adresse (Superadmin → Einstellungen) setzen');
        });
        // Audit ARC-2: Massen-Mails (Mitteilungen, Sammel-Einladung und
        // -Verlängerung, Lern-Erinnerungen, Lerngruppen) laufen über die
        // Queue -- ohne laufenden Worker (queue:work) gehen sie nie raus.
        if (config('queue.default') === 'sync') {
            $add('Hintergrunddienste', 'Queue-Worker (QUEUE_CONNECTION nicht „sync“)', 'warn', 'Massen-Mails laufen sonst im Request (Zeitüberschreitung bei vielen Empfängern)');
        } else {
            $beat = Cache::get(self::WORKER_HEARTBEAT_KEY);
            $add('Hintergrunddienste', 'Queue-Worker (queue:work)', $beat && now()->timestamp - $beat < 300 ? 'ok' : 'fail',
                $beat ? 'zuletzt '.self::ago($beat) : 'noch nie gelaufen (Vorlage deploy/supervisor-worker.conf)');
        }
        if (config('queue.default') === 'database') {
            $safely(function () use ($add) {
                // Älteste fällige, noch nicht gestartete Job-Wartezeit.
                $oldest = DB::connection('pgsql_admin')->table(config('queue.connections.database.table', 'jobs'))
                    ->whereNull('reserved_at')->where('available_at', '<=', now()->timestamp)->min('available_at');
                $wait = $oldest ? now()->timestamp - (int) $oldest : 0;
                $stuck = $wait > self::MAX_JOB_WAIT_SECONDS;
                $add('Hintergrunddienste', 'Queue (keine hängenden Jobs)', $stuck ? 'fail' : 'ok',
                    $stuck ? 'ältester Job wartet seit '.intdiv($wait, 60).' Minuten: Worker (queue:work) läuft nicht oder ist überlastet'
                        : ($oldest ? 'älteste Wartezeit '.$wait.' s' : ''));
            });
        }
        $safely(function () use ($add) {
            $failed = DB::connection('pgsql_admin')->table(config('queue.failed.table', 'failed_jobs'))->count();
            $add('Hintergrunddienste', 'Fehlgeschlagene Jobs', $failed === 0 ? 'ok' : 'warn', $failed ? "{$failed} Job(s), siehe php artisan queue:failed" : '');
        });
        // Ab einigen Schulen trägt die Datenbank Cache, Sessions und Queue
        // spürbar mit: Redis entlastet sie (deploy/README.md, „Redis“).
        if (config('cache.default') === 'database' && config('session.driver') === 'database' && config('queue.default') === 'database') {
            $safely(function () use ($add) {
                $schools = Tenant::on('pgsql_admin')->whereIn('status', ['active', 'trial'])->count();
                if ($schools > self::REDIS_RECOMMENDED_FROM_SCHOOLS) {
                    $add('Hintergrunddienste', 'Cache, Sessions und Queue auf Redis', 'warn', "{$schools} Schulen, alles läuft über die Datenbank: Redis empfohlen (deploy/README.md)");
                }
            });
        }
        $backup = BackupApplication::latestBackupAt();
        $maxAge = (int) config('backup.max_age_hours', 26);
        $add('Hintergrunddienste', 'Datensicherung (app:backup)', $backup && $backup->diffInHours(now()) < $maxAge ? 'ok' : 'fail', $backup ? 'zuletzt '.$backup->format('d.m.Y H:i').(config('backup.disk') ? ', extern: '.config('backup.disk') : ', nur lokal') : 'noch keine Sicherung');
        if ($backup && ! config('backup.disk')) {
            $add('Hintergrunddienste', 'Sicherung außerhalb des Servers (BACKUP_DISK)', 'warn', 'fällt der Server aus, sind lokale Sicherungen mit weg');
        }
        if (config('backup.disk')) {
            $remote = BackupApplication::latestRemoteBackupAt();
            $add('Hintergrunddienste', 'Externe Sicherung erfolgreich', $remote && $remote->diffInHours(now()) < $maxAge ? 'ok' : 'fail', $remote ? 'zuletzt '.$remote->format('d.m.Y H:i') : 'noch keine vollständige externe Kopie');
            $add('Hintergrunddienste', 'Externe Sicherung verschlüsselt (BACKUP_ENCRYPTION_PUBLIC_KEY)', config('backup.encryption_public_key') ? 'ok' : 'warn', config('backup.encryption_public_key') ? '' : 'Kopien liegen sonst lesbar beim Speicheranbieter (php artisan app:backup-keygen)');
        }
        $free = @disk_free_space(is_dir((string) config('backup.path')) ? (string) config('backup.path') : storage_path());
        if ($free !== false) {
            [$status, $detail] = self::freeSpace((int) $free, BackupApplication::latestBackupBytes());
            $add('Hintergrunddienste', 'Freier Speicher für Sicherungen', $status, $detail);
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
            // Entfernte Datenbank: ohne Zertifikatsprüfung lassen sich Zugangsdaten und
            // Inhalte unterwegs mitlesen (Standard „prefer“ prüft nichts, deploy/README.md §1).
            $dbHost = (string) config('database.connections.pgsql.host');
            if (! in_array($dbHost, ['', '127.0.0.1', 'localhost', '::1'], true) && ! str_starts_with($dbHost, '/')) {
                $sslmode = (string) config('database.connections.pgsql.sslmode');
                $add('Datenbank', 'Verschlüsselte DB-Verbindung (DB_SSLMODE=verify-full)', $sslmode === 'verify-full' ? 'ok' : 'warn', "Host: {$dbHost}, sslmode: {$sslmode}");
            }
            $add('Datenbank', 'Starke DB-Passwörter (≥ 16 Zeichen)', $weak->isEmpty() ? 'ok' : 'fail', $weak->isEmpty() ? '' : 'zu schwach: '.$weak->implode(', '));
        });

        // --- Inhalte und Konten --------------------------------------------
        $safely(function () use ($add) {
            $superadmins = User::on('pgsql_admin')->where('is_superadmin', true)->get();
            $add('Konten', 'Superadmin vorhanden', $superadmins->isNotEmpty() ? 'ok' : 'fail', $superadmins->isNotEmpty() ? '' : 'php artisan app:create-superadmin <E-Mail>');
            $without2fa = $superadmins->reject(fn (User $u) => $u->hasTwoFactor())->count();
            $add('Konten', 'Superadmins mit Zwei-Faktor-Anmeldung', $without2fa === 0 ? 'ok' : 'warn', $without2fa ? "{$without2fa} ohne 2FA" : '');

            $placeholders = VideoLesson::on('pgsql_admin')->where('video_url', VideoLesson::PLACEHOLDER_URL)->count();
            // Lernende sähen sonst ein Beispielvideo statt der Lektion.
            $add('Inhalte', 'Videolektionen mit echtem Video', $placeholders === 0 ? 'ok' : 'fail', $placeholders ? "{$placeholders} Lektion(en) noch mit Platzhaltervideo" : '');

            $incomplete = Tenant::on('pgsql_admin')->whereIn('status', ['active', 'trial'])->with('branding')->get()
                ->filter(fn (Tenant $t) => ! $t->branding || LegalPages::missingImprintFields($t->branding) !== [])
                ->pluck('name');
            $add('Inhalte', 'Impressumsangaben aller aktiven Schulen', $incomplete->isEmpty() ? 'ok' : 'warn', $incomplete->isEmpty() ? '' : 'unvollständig: '.$incomplete->take(5)->implode(', ').($incomplete->count() > 5 ? ' …' : ''));

            // Lizenzierte/amtliche Fragenbilder sonst ohne Anmeldung unter /storage/... abrufbar.
            $exposed = QuestionMedia::protectedFilesOnPublicDisk();
            $add('Inhalte', 'Geschützte Fragenbilder nicht öffentlich abrufbar', $exposed->isEmpty() ? 'ok' : 'warn', $exposed->isEmpty() ? '' : "{$exposed->count()} Datei(en) noch auf der public-Disk, z. B. {$exposed->first()} (unter gleichem Pfad nach storage/app/private verschieben)");
            // Tipp-PDFs (lizenziert) und Bilder der Videoschritte (Kursinhalt) ebenso.
            $more = TipPdf::filesOnPublicDisk()->merge(VideoStepImage::filesOnPublicDisk())->unique()->values();
            $add('Inhalte', 'Tipp-PDFs und Videoschritt-Bilder nicht öffentlich abrufbar', $more->isEmpty() ? 'ok' : 'warn', $more->isEmpty() ? '' : "{$more->count()} Datei(en) noch auf der public-Disk, z. B. {$more->first()} (unter gleichem Pfad nach storage/app/private verschieben, siehe Migration 000820)");
            // Kartenausschnitte (Lizenz nur für Kursteilnehmer) ebenso.
            $charts = NavigationChart::filesOnPublicDisk();
            $add('Inhalte', 'Kartenausschnitte nicht öffentlich abrufbar', $charts === [] ? 'ok' : 'warn', $charts === [] ? '' : count($charts).' Datei(en) noch unter storage/app/public/'.NavigationChart::DIRECTORY.' (nach storage/app/private/'.NavigationChart::DIRECTORY.' verschieben)');

            $footer = @file_get_contents(resource_path('views/components/site-footer.blade.php')) ?: '';
            // Sichtbar auf jeder Seite jeder Schule: vor dem Start zwingend ersetzen.
            $add('Inhalte', 'Footer ohne Platzhalter', str_contains($footer, 'XXX') ? 'fail' : 'ok', str_contains($footer, 'XXX') ? 'Footer enthält noch „XXX“' : '');
        });

        return $checks;
    }

    /**
     * Freier Speicher reicht für mindestens drei weitere Sicherungen (und 1 GiB)?
     *
     * @return array{0: string, 1: string}
     */
    public static function freeSpace(int $freeBytes, ?int $latestBackupBytes): array
    {
        $needed = max(1024 ** 3, 3 * (int) $latestBackupBytes);
        $gib = fn (int|float $bytes) => number_format($bytes / 1024 ** 3, 1, ',', '.').' GiB';

        if ($latestBackupBytes !== null && $freeBytes < $latestBackupBytes) {
            return ['fail', "frei {$gib($freeBytes)}, die letzte Sicherung brauchte {$gib($latestBackupBytes)}"];
        }

        return [$freeBytes < $needed ? 'warn' : 'ok', "frei {$gib($freeBytes)}".($freeBytes < $needed ? ", empfohlen mind. {$gib($needed)}" : '')];
    }

    /**
     * Verschickt der Mailer wirklich? failover zählt mit echtem ersten Mailer
     * (Laravel-Standard: smtp, dann log), roundrobin nur, wenn alle echt sind.
     */
    private static function sendsRealMail(string $mailer, int $depth = 0): bool
    {
        $transport = (string) (config("mail.mailers.{$mailer}.transport") ?? $mailer);
        $children = array_values((array) config("mail.mailers.{$mailer}.mailers"));

        return match (true) {
            $depth > 3 => false,
            in_array($transport, ['ses', 'ses-v2', 'postmark', 'resend', 'mailgun'], true) => true,
            $transport === 'smtp' => ! in_array((string) config("mail.mailers.{$mailer}.host"), ['127.0.0.1', 'localhost', 'mailpit', ''], true),
            $transport === 'failover' => $children !== [] && self::sendsRealMail((string) $children[0], $depth + 1),
            $transport === 'roundrobin' => $children !== [] && collect($children)->every(fn ($child) => self::sendsRealMail((string) $child, $depth + 1)),
            default => false,
        };
    }

    private static function ago(int $timestamp): string
    {
        return Carbon::createFromTimestamp($timestamp)->locale('de')->diffForHumans();
    }
}
