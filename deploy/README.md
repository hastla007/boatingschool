# Betriebshandbuch: Server, Deploy, Backup

Vorlagen in diesem Ordner gehen von einem Linux-Server (Ubuntu/Debian) aus,
mit der App unter `/var/www/boatingschool`, einem Deploy-Benutzer `deploy`
und PHP 8.3-FPM. Pfade, Domain (`lernen.example.de`) und Benutzer anpassen.
Mit Laravel Forge oder Ploi gelten dieselben Schritte; dort trägt man Cron,
Worker und Deploy-Skript in der Oberfläche ein.

| Datei | Zweck |
|---|---|
| `deploy.sh` | Update auf dem Server (Sperre, Abhängigkeiten/Build vorab, Wartungsmodus, Sicherung, Migrationen, Inhalte bei Bedarf, Caches, Go-live-Check); optional mit Release-Ordnern und Rollback |
| `Caddyfile` | Webserver mit automatischen Zertifikaten, auch für eigene Domains der Schulen (empfohlen) |
| `nginx.conf` | Alternative mit nginx und Wildcard-Zertifikat |
| `php-fpm-pool.conf` | PHP-FPM-Pool (Benutzer, Limits, nur `.php` ausführen) |
| `crontab` | Scheduler (jede Minute, Ausgabe nach `storage/logs/scheduler.log`) |
| `supervisor-worker.conf` | Queue-Worker (Pflicht: Massen-Mails laufen über die Queue) |
| `../.github/workflows/deploy.yml` | Deploy per Knopfdruck aus GitHub, Produktion oder Staging (optional) |

## 1. Erstinstallation

1. **Pakete**: PHP 8.3–8.5 mit `pgsql`, `mbstring`, `xml`, `zip`, `gd`, `intl`, `bcmath`, `curl`;
   PostgreSQL 16–18 (inkl. `pg_dump` derselben Hauptversion wie der Server); Node 22 oder neuer;
   Composer; Caddy oder nginx. Getestet: PHP 8.3 und 8.5, PostgreSQL 16 und 18, Node 22 und 26,
   nginx 1.31 (Tests, Migrationen, Seed, Build, Datensicherung samt Wiederherstellung).
2. **DNS**: `lernen.example.de` (A/AAAA) auf den Server. Die Schulen sind unter
   `lernen.example.de/<slug>` erreichbar, ein Wildcard-Eintrag ist dafür nicht nötig.
   Optional: `*.lernen.example.de` für Subdomains (`TENANT_URL_MODE=subdomain`), eigene
   Domains der Schulen per CNAME auf `lernen.example.de`.
3. **Datenbank**: Rollen und Datenbank wie im Haupt-README („Zwei DB-Rollen anlegen“),
   mit zufälligen Passwörtern (`openssl rand -base64 24`). Ein Pooler (PgBouncer)
   nur im Session-Modus. Läuft die Datenbank auf einem anderen Host, `DB_SSLMODE=verify-full`
   setzen (Standard `prefer` ist nur für lokale Verbindungen gedacht und prüft kein Zertifikat).
4. **Code**: `git clone … /var/www/boatingschool`, dann `cp .env.example .env` und ausfüllen:
   `APP_URL=https://lernen.example.de`, `CENTRAL_DOMAIN=lernen.example.de`, DB-Zugänge,
   Mail-Zugang (SMTP des Mailanbieters), `MAIL_FROM_ADDRESS`, `BACKUP_DISK` mit
   `BACKUP_ENCRYPTION_PUBLIC_KEY` (§3), `OPS_HEARTBEAT_URL` und `OPS_ALERT_EMAIL` (§5),
   `SUPERADMIN_HOST` (§4), optional `SENTRY_LARAVEL_DSN`.
   `TRUSTED_PROXIES` leer lassen, außer ein Load-Balancer steht davor (dann dessen
   Adressen, nie `*`). Danach `chmod 600 .env` (enthält `APP_KEY` und alle Zugangsdaten).
   `SUPERADMIN_PASSWORD` nur für das erste `app:create-superadmin` setzen und danach entfernen.
5. **Einrichten**:
   ```bash
   composer install --no-dev --optimize-autoloader
   php artisan key:generate
   npm ci && npm run build
   php artisan migrate --database=pgsql_admin --force
   php artisan db:seed --database=pgsql_admin --force   # Fragen, Module, Kurse (keine Demo-Schulen in production)
   php artisan storage:link
   php artisan app:create-superadmin du@example.de
   php artisan optimize
   ```
   Danach als Superadmin anmelden und die Zwei-Faktor-Anmeldung einschalten.
6. **Rechte und PHP**: `storage/` und `bootstrap/cache/` gehören dem Deploy-Benutzer, PHP-FPM
   läuft als derselbe Benutzer: Vorlage `deploy/php-fpm-pool.conf` (setzt auch Upload-Größe
   25 MB, Speicher 512 MB und Laufzeit; die PHP-Standardwerte von 2 MB/128 MB/30 s reichen
   für Importe und Exporte nicht).
   Ausgehende Verbindungen braucht der Server zu: SMTP des Mailanbieters,
   `api.pwnedpasswords.com` (Prüfung auf geleakte Passwörter), optional S3-Speicher und Sentry.
7. **Webserver**: `Caddyfile` nach `/etc/caddy/Caddyfile` (oder `nginx.conf` + certbot), neu laden.
8. **Cron**: Zeile aus `crontab` mit `crontab -e` als Deploy-Benutzer eintragen. Die Ausgabe
   landet in `storage/logs/scheduler.log`; mit logrotate begrenzen (§5).
9. **Queue-Worker** (Pflicht): `apt install supervisor`, dann `supervisor-worker.conf` nach
   `/etc/supervisor/conf.d/boatingschool-worker.conf` und
   `supervisorctl reread && supervisorctl update`. Mitteilungen per Mail, Sammel-Einladungen,
   Sammel-Verlängerungen, Lern-Erinnerungen an viele und Lerngruppen-Einladungen werden nur
   eingereiht (`QUEUE_CONNECTION=database`) und erst vom Worker verschickt -- ohne Worker
   bleiben diese Mails liegen. Jeder Job läuft im Kontext der Schule, aus deren Bereich die
   Mail kommt (RLS, Absender wie im Request). `deploy.sh` startet den Worker nach jedem Update
   neu (`queue:restart`). Endgültig gescheiterte Jobs: `php artisan queue:failed`, erneut
   anstoßen mit `php artisan queue:retry all`.
10. **Prüfen**: `php artisan app:check-production` bzw. im Superadmin unter „System“.
   Nach einigen Minuten müssen Scheduler, Queue-Worker (Lebenszeichen) und (am nächsten
   Morgen) Datensicherung grün sein; ein fälliger Job, der länger als 10 Minuten wartet,
   gilt als hängend.

## 2. Updates einspielen

```bash
cd /var/www/boatingschool && ./deploy/deploy.sh          # Branch main (zieht vorher eine Sicherung)
./deploy/deploy.sh main <commit> --with-content          # genau diesen Commit, Inhalte erzwingen
BUILD_DIR=/tmp/build ./deploy/deploy.sh                  # fertigen Vite-Build übernehmen
```

Oder in GitHub unter *Actions → Deploy → Run workflow* (Secrets siehe `deploy.yml`). Die
Eingabe „environment“ wählt das Ziel: `production` (Standard) rollt nur `main` aus,
`staging` jeden Branch, von dem aus der Workflow gestartet wird (siehe Abschnitt 8). In
beiden Fällen nur einen Commit, für den „Tests“ grün ist. Die Secrets (`DEPLOY_HOST`,
`DEPLOY_USER`, `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`, `DEPLOY_PATH`) liegen je Umgebung unter
*Settings → Environments → production* bzw. *staging*; bei „production“ zusätzlich unter
„Deployment branches“ nur `main` zulassen und ggf. eine Freigabe (Required reviewers)
verlangen. Bisherige Repository-Secrets in die Umgebung „production“ verschieben. Er baut die
Frontend-Assets für genau diesen Commit in einem eigenen Job (`npm ci && npm run build`),
kopiert `public/build` per `scp` in ein temporäres Verzeichnis auf dem Server und startet
`deploy.sh` mit `BUILD_DIR` -- auf dem Server wird dann nicht gebaut. Die Option
„with_content“ im Workflow entspricht `--with-content`.

Ablauf (Modus `inplace`, Standard): Eine Sperre (`flock` auf `.deploy/lock`) verhindert zwei
gleichzeitige Deploys. Der neue Stand wird zuerst nur geholt (nur Fast-forward, nur Commits
auf dem Branch); Composer-Abhängigkeiten und Assets (bzw. der Build aus `BUILD_DIR`) entstehen
in einem Vorbereitungsordner (`.deploy/staging`, ein `git worktree`), während die Seite
weiterläuft. Erst dann folgen Wartungsmodus, Sicherung (`app:backup --deploy`), Umschalten
(Code, `vendor/` und `public/build` per Umbenennen), Migrationen, Seeder (nur bei Bedarf) und
Caches -- die Wartungszeit besteht im Wesentlichen aus Sicherung und Migrationen.

Schlägt ein Schritt vor dem Wartungsmodus fehl, läuft die Seite unverändert weiter. Danach
bleibt sie im Wartungsmodus; das Skript gibt den vorherigen Commit aus. Ursache beheben und
das Skript erneut starten, zurück ohne Update mit `php artisan up` (nur wenn noch nicht
umgeschaltet wurde) oder per Rückroll (unten).

**Assets**: Ohne `BUILD_DIR` (bzw. `--build-dir DIR`) baut das Skript auf dem Server (Node
nötig; vor dem Wartungsmodus im Vorbereitungs- bzw. Release-Ordner). Fehlt im Verzeichnis `manifest.json` oder gehört die beiliegende
`build-sha.txt` zu einem anderen Commit, baut es ebenfalls auf dem Server und sagt das im Log.

**Inhalte** (`db:seed`: Fragen, Erklärungen, Navigation, Praxistrainer; idempotent,
Korrekturen aus dem Superadmin bleiben erhalten) laufen nur, wenn

- sich seit dem zuletzt ausgerollten Commit etwas unter `database/seeders` oder
  `database/data` geändert hat (der Stand steht in `storage/app/deployed_sha`),
- noch kein Stand gespeichert bzw. er im Repository unbekannt ist (erster Lauf), oder
- `--with-content` übergeben wird (z. B. wenn ein Seeder Daten aus `app/` nutzt, die sich geändert haben).

Das Log nennt jeweils, welcher Fall eingetreten ist („Seeder laufen: …“ bzw. „Seeder
übersprungen: …“). Darf der Deploy-Benutzer PHP-FPM neu laden, `PHP_FPM_SERVICE=php8.3-fpm`
setzen (leert den Opcache).

Migrationen sind nur vorwärts gedacht. Das Skript zieht deshalb vor jedem Update eine
Sicherung (`php artisan app:backup --deploy`: nur lokal, Ordner `<Zeitstempel>-deploy`, es
bleiben die letzten `BACKUP_KEEP_DEPLOY`, Standard 3); schlägt sie fehl, bricht der Deploy ab.
`php artisan queue:restart` am Ende lässt die Queue-Worker mit dem neuen Code neu starten.

### Rückroll

Ist das Update eingespielt, aber fehlerhaft (oder bricht es nach dem Umschalten ab), im
Modus `inplace`:

```bash
cd /var/www/boatingschool
php artisan down
PREV=<vorheriger Commit aus der Deploy-Ausgabe>
git checkout --quiet --detach "$PREV"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci && npm run build
# Nur wenn Migrationen gelaufen sind: Datenbank aus der Sicherung vor dem Deploy
# zurückspielen (neuester Ordner storage/app/backups/<Zeitstempel>-deploy, Ablauf wie
# in §3 „Backup zurückspielen“, ohne die ZIP-Dateien). Achtung: Änderungen seit dem
# Deploy gehen dabei verloren.
php artisan optimize && php artisan queue:restart && php artisan up
```

Der nächste reguläre Deploy (`./deploy/deploy.sh`) wechselt wieder auf `main` und spielt
den dann korrigierten Stand ein. Im Modus `releases` geht der Code-Rückweg schneller mit
`./deploy/deploy.sh --rollback` (nächster Abschnitt).

### Release-Ordner und Rollback (optional)

Standard bleibt der Modus `inplace`: ein Git-Checkout, der wie oben aktualisiert wird --
bestehende Server müssen nichts ändern. Mit `DEPLOY_MODE=releases` entsteht jedes Update in
einem eigenen Ordner:

```text
/var/www/boatingschool/
├── current -> releases/20260301-031500-1a2b3c4d5e6f   (Webserver, Cron, Worker zeigen hierher)
├── releases/<Zeitstempel>-<Commit>/                    (die letzten 5 Stände, KEEP_RELEASES)
├── shared/.env, shared/storage/                        (in jedem Stand als Symlink)
└── repo.git                                            (Git-Spiegel, aus dem die Stände entstehen)
```

Abhängigkeiten, Assets und Caches baut das Skript, während der alte Stand weiterläuft; der
Wartungsmodus umfasst nur Sicherung (`app:backup --deploy`), Migrationen (aus dem neuen
Stand) und das atomare Umschalten von `current`. Die Sperre liegt hier auf
`<basis>/.deploy.lock`. Bricht etwas vor dem Umschalten ab, wird der halbe Ordner entfernt
und der alte Stand läuft weiter (nach einem Fehler in den Migrationen bleibt der
Wartungsmodus an: Fehler beheben und erneut starten, oder `php artisan up`).

```bash
cd /var/www/boatingschool/current && ./deploy/deploy.sh          # erkennt den Modus selbst
./deploy/deploy.sh --rollback                                    # current auf den vorherigen Stand
```

**Rollback betrifft nur den Code.** Migrationen des neueren Stands bleiben in der Datenbank;
der ältere Code muss mit dem neueren Schema zurechtkommen (bei rein ergänzenden Migrationen
meist der Fall). Passt es nicht, die Sicherung vom Deploy zurückspielen (Abschnitt 3).

**Umstieg eines bestehenden Servers** (einmalig, ca. 15 Minuten, Beispiel mit neuem
Basisordner `/var/www/boatingschool-app`):

```bash
ALT=/var/www/boatingschool; NEU=/var/www/boatingschool-app
mkdir -p $NEU/shared
cd $ALT && php artisan down
cp -a $ALT/.env $NEU/shared/.env
cp -a $ALT/storage $NEU/shared/storage          # Uploads, Sicherungen, Logs, deployed_sha
# erster Release-Stand (legt repo.git und current an, Seeder nur bei Bedarf):
DEPLOY_MODE=releases DEPLOY_BASE=$NEU ./deploy/deploy.sh main
```

Danach auf `$NEU/current` umstellen und neu laden: Webserver-Root
(`root $NEU/current/public;` -- nginx nutzt bereits `$realpath_root`, das ist bei Symlinks
nötig; Caddy: `root * $NEU/current/public` und in `php_fastcgi` die Option
`resolve_root_symlink`), Cron (`cd $NEU/current && php artisan schedule:run`),
Supervisor (`command`/`directory` auf `$NEU/current`), `PHP_FPM_SERVICE` setzen (Reload
leert Opcache und Realpath-Cache) und im GitHub-Secret `DEPLOY_PATH=$NEU/current`.
`php artisan up` beendet den Wartungsmodus (falls noch an). Der alte Ordner kann nach
einigen Tagen weg; `BACKUP_PATH` zeigt weiter auf `storage/app/backups` (jetzt in
`shared/`).

## 3. Datensicherung

`app:backup` läuft täglich um 03:15 (Europe/Berlin) und legt unter `storage/app/backups/<Zeitstempel>/` ab:

- `database.dump`: kompletter Datenbank-Dump (pg_dump, Custom-Format, inkl. Rechte und RLS-Policies)
- `uploads.zip`: öffentliche Uploads (Logos, Favicons, öffentliche Bilder; `storage/app/public`)
- `protected-media.zip`: die geschützten Dateien der privaten Disk: Fragen- und
  Praxistrainer-Bilder, Kartenausschnitte, Kursdateien der App, Tipp-PDFs und Bilder der
  Videoschritte (`storage/app/private/question-media`, `…/navigation-charts`, `…/course-files`,
  `…/tip-attachments`, `…/video-steps`) sowie jeder weitere Ordner dort; Videoschritt-Bilder
  mit älterem Pfad außerhalb dieser Ordner kommen einzeln dazu. Sie werden nur über signierte bzw. geprüfte Adressen ausgeliefert und
  gehören ausdrücklich in die Sicherung. Draußen bleiben nur kurzlebige Dateien
  (Datenexporte `api-exports`/`exports`, Import-Uploads `imports`).
- `manifest.json`: Zeitpunkt, Größen, SHA-256-Prüfsummen
- `remote.json`: nur wenn die externe Kopie vollständig ist (wertet der Go-live-Check aus)

Mit `BACKUP_DISK=s3` (S3-kompatibler Speicher, z. B. Hetzner Object Storage) geht jede Sicherung
zusätzlich nach außen; das Paket `league/flysystem-aws-s3-v3` ist in `composer.json` enthalten
(fehlt es, bleibt es bei der lokalen Sicherung, und `app:check-production` meldet das).
Aufbewahrung: `BACKUP_KEEP_DAYS` (Standard 14). Videos liegen beim Videoanbieter und sind
nicht Teil der Sicherung. Schlägt nur die externe Kopie fehl, bleibt die lokale Sicherung
erhalten; der Lauf endet trotzdem mit Fehler (Betriebsalarm an `OPS_ALERT_EMAIL`), und der
Go-live-Check meldet „Externe Sicherung erfolgreich“ als Fehler, bis wieder eine Kopie
vollständig ist.

**Verschlüsselung (dringend empfohlen):** Mit `BACKUP_ENCRYPTION_PUBLIC_KEY` werden alle
Dateien außer `manifest.json` vor dem Hochladen verschlüsselt (`*.enc`; libsodium:
X25519-versiegelter Schlüssel je Datei, XChaCha20-Poly1305 im Datenstrom, Manipulation
und abgeschnittene Dateien werden erkannt). Lokal bleiben die Dateien unverschlüsselt.
Der geheime Schlüssel liegt nicht auf dem Server: Wer Server oder S3-Zugang übernimmt, kann
die externen Kopien damit nicht lesen.

```bash
# Auf einem Arbeitsrechner mit ausgecheckter App (nicht auf dem Server):
php artisan app:backup-keygen --secret-key-file=backup-geheim.key
# -> Zeile BACKUP_ENCRYPTION_PUBLIC_KEY=… in die .env des Servers,
#    backup-geheim.key in den Passwortmanager/Tresor (ohne ihn sind die Kopien wertlos).
```

Probe nach der ersten Sicherung: eine `.enc`-Datei herunterladen und mit
`php artisan app:backup-decrypt database.dump.enc --key-file=backup-geheim.key` entschlüsseln;
die ausgegebene SHA-256-Prüfsumme muss zu `manifest.json` passen.

**Löschschutz:** Der Server sollte die externen Kopien nicht löschen können -- sonst kann
ein Angreifer mit Serverzugriff (oder Ransomware) auch sie entfernen.

- Bucket mit **Versionierung** und **Object Lock** (Modus „Compliance“ oder „Governance“,
  z. B. 30 Tage) anlegen (bei Hetzner/AWS nur beim Anlegen möglich) und eine
  **Lifecycle-Regel** setzen, die `backups/` nach `BACKUP_KEEP_DAYS` (plus Sperrfrist)
  löscht, ältere Versionen ebenfalls (Noncurrent Version Expiration). Dann
  `BACKUP_PRUNE_REMOTE=false`: `app:backup` löscht extern nichts mehr.
- Zugangsschlüssel des Servers nur mit Schreibrecht auf `backups/*` (`s3:PutObject`); ohne
  `s3:DeleteObject` und ohne Rechte zum Ändern von Bucket-Einstellungen. Mit
  `BACKUP_PRUNE_REMOTE=true` (Standard: externe Sicherungen nach `BACKUP_KEEP_DAYS` löschen,
  nur sinnvoll ohne Object Lock) braucht er zusätzlich `s3:ListBucket` und `s3:DeleteObject`.
- Optional `OPS_BACKUP_HEARTBEAT_URL`: Ping an einen Heartbeat-Dienst nach jeder erfolgreichen
  täglichen Sicherung (bleibt er aus, alarmiert der Dienst).

**Probe-Wiederherstellung**: `php artisan app:backup:verify` (monatlich am 2. um 04:40 per
Scheduler, Betriebsalarm bei Fehlern) nimmt die neueste lokale Sicherung (oder `--path=`),
prüft ihre Prüfsummen, spielt den Dump in eine temporäre Datenbank (`<db>_verify_<Zeitstempel>`),
prüft, dass wichtige Tabellen Zeilen haben (`BACKUP_VERIFY_TABLES`) und die Migrationen zur
laufenden Datenbank passen, und löscht die temporäre Datenbank wieder. Dafür braucht die
Owner-Rolle das Recht, Datenbanken anzulegen (einmalig als postgres:
`ALTER ROLE boatingschool CREATEDB;`), sonst meldet der Befehl genau das. Lokale Sicherungen
sind unverschlüsselt, ein Schlüssel ist dafür nicht nötig; die verschlüsselten externen
Kopien prüft man mit der Entschlüsselungsprobe oben (`app:backup-decrypt`).

### Backup zurückspielen

**Wichtig: `.env` gehört nicht in die Sicherung, wird aber gebraucht.** `APP_KEY`
verschlüsselt u. a. die Zwei-Faktor-Schlüssel und Wiederherstellungscodes aller
Konten (auch der Superadmins). Mit einem neuen `APP_KEY` (`key:generate`) lassen
sie sich nach dem Zurückspielen nicht mehr entschlüsseln. `APP_KEY` sowie die
Geheimnisse der Shop-Anbindung (`COMMERCE_*_SECRET`) und die Zugangsdaten für
Datenbank, Mail und S3 deshalb getrennt und sicher aufbewahren (z. B. im
Passwortmanager) und beim Zurückspielen wieder in `.env` eintragen.

Aus der externen Kopie: den Ordner `backups/<Zeitstempel>/` herunterladen und die
`.enc`-Dateien vorher entschlüsseln (`php artisan app:backup-decrypt <Datei>.enc
--key-file=backup-geheim.key`, auf jedem Rechner mit ausgecheckter App, ohne Datenbank).

Auf einem Server mit denselben Rollen (`boatingschool`, `boatingschool_app`):

```bash
php artisan down
# Datenbank neu anlegen (als postgres-Superuser)
sudo -u postgres psql -c "DROP DATABASE boatingschool" -c "CREATE DATABASE boatingschool OWNER boatingschool" \
  -c "GRANT CONNECT ON DATABASE boatingschool TO boatingschool_app"
B=storage/app/backups/<Zeitstempel>   # bzw. vom S3-Speicher heruntergeladen
# externe Kopie: .enc-Dateien vorher mit app:backup-decrypt entschlüsseln (siehe oben)
PGPASSWORD=… pg_restore -h 127.0.0.1 -U boatingschool -d boatingschool --exit-on-error $B/database.dump
# Uploads
unzip -o $B/uploads.zip -d storage/app/public
unzip -o $B/protected-media.zip -d storage/app/private   # falls vorhanden
php artisan optimize:clear && php artisan optimize && php artisan up
```

**Einmal vor dem Start ausprobieren** (z. B. auf einem Testserver), damit im Ernstfall jeder
Schritt sitzt. Entschlüsselte Dateien danach löschen (`shred -u` bzw. `rm`).

## 4. Adressen, Zertifikate und eigene Domains

Standard ist `lernen.example.de/<slug>` (`TENANT_URL_MODE=path`): ein Zertifikat für die
zentrale Domain genügt, jede Schule hat trotzdem eine eigene Anmeldung (Cookies gelten nur
für ihren Pfad). Der Slug kann keinen Namen eines App-Bereichs annehmen (`login`, `courses`,
`superadmin` …); das prüft das Anlegen im Superadmin. Später auf Subdomains umsteigen:
Wildcard-DNS und -Zertifikat einrichten, `TENANT_URL_MODE=subdomain` setzen. Die Pfad-Adressen
funktionieren danach weiter.

- **Caddy (empfohlen)**: holt Zertifikate automatisch beim ersten Aufruf einer Domain. Vorher
  fragt Caddy `/tls-check`, ob die Domain die zentrale Domain, die Subdomain einer aktiven Schule
  oder deren eingetragene eigene Domain ist; fremde Domains bekommen kein Zertifikat.
- **nginx**: Zertifikat für die zentrale Domain (`certbot --nginx -d lernen.example.de`);
  nur für Subdomains ein Wildcard-Zertifikat per DNS-Challenge; eigene Domains der Schulen je
  einzeln per certbot.

**Superadmin-Bereich getrennt halten (Empfehlung für Produktion).** Im Pfad-Modus liegen alle
Schulen und der Superadmin unter demselben Ursprung (`lernen.example.de`). Die Cookies sind
zwar je Pfad getrennt, das ist aber keine Sicherheitsgrenze: Skript auf einer Schulseite
(z. B. eine XSS-Lücke) könnte im selben Browser Seiten des Superadmins lesen und Anfragen
dorthin schicken. Deshalb `SUPERADMIN_HOST` setzen (z. B. `admin.lernen.example.de`, DNS-Eintrag
auf den Server; Caddy holt das Zertifikat automatisch, bei nginx den Namen ins Zertifikat und
in `server_name` aufnehmen). Dann ist `/superadmin` nur noch dort erreichbar (überall sonst
404), mit eigenem Session-Cookie; Superadmins melden sich dort an. `SESSION_DOMAIN` dabei leer
lassen. Alternativ den Subdomain-Modus nutzen. Der Go-live-Check weist im Pfad-Modus ohne
`SUPERADMIN_HOST` darauf hin.

## 5. Überwachung

- **Totmann-Schalter (Pflicht)**: Der Go-live-Check und die Betriebsalarme laufen selbst über
  den Scheduler -- steht Cron oder der ganze Server, meldet sich niemand. Deshalb bei einem
  externen Heartbeat-Dienst (z. B. healthchecks.io, Better Stack Heartbeats, Cronitor) einen
  Check mit Intervall 1 Minute und Karenz ca. 10 Minuten anlegen und dessen Ping-Adresse als
  `OPS_HEARTBEAT_URL` eintragen; der Scheduler pingt sie jede Minute. Bleibt der Ping aus,
  alarmiert der Dienst per Mail/SMS. Der Go-live-Check warnt, solange die Adresse fehlt.
- **Alarmempfänger**: `OPS_ALERT_EMAIL` (sonst die Support-Adresse der Plattform); fehlt beides,
  meldet der Go-live-Check einen Fehler.
- **Erreichbarkeit**: externen Uptime-Dienst (z. B. UptimeRobot, Better Stack) auf
  `https://lernen.example.de/up` richten (liefert 200, ohne Session/Datenbankzugriff).
- **Fehler**: `SENTRY_LARAVEL_DSN` setzen (EU-Standort), sonst nur `storage/logs/laravel-*.log`
  (14 Tage, `LOG_DAILY_DAYS`).
- **Go-live-Check**: `php artisan app:check-production` (Exit-Code 1 bei Fehlern, eignet sich
  für einen täglichen Cron mit Mail bei Fehlern) bzw. Superadmin → „System“. Er prüft u. a.
  auch die letzte vollständige externe Sicherung, deren Verschlüsselung, den freien Speicher
  (mind. dreimal die letzte Sicherung), `TRUSTED_PROXIES`, die Rechte der `.env` und
  `SESSION_SECURE_COOKIE`.
- **Logs**: Die Webserver-Vorlagen schreiben Zugriffslogs ohne Query-Strings und ohne Token im
  Pfad (Passwort-Reset, Signaturen, E-Mail-Adressen) und behalten sie 14 Tage (Caddy:
  `roll_keep_for`; nginx: logrotate mit `daily`/`rotate 14`). Scheduler-Ausgabe begrenzen, z. B.
  `/etc/logrotate.d/boatingschool`:
  ```
  /var/www/boatingschool/storage/logs/scheduler.log {
      daily
      rotate 14
      compress
      missingok
      notifempty
      copytruncate
  }
  ```
  Im Modus `releases` liegt die Datei unter `<basis>/shared/storage/logs/`.
- **Queue**: `app:check-production` meldet einen Worker ohne Lebenszeichen (über 5 Minuten)
  und fällige Jobs, die länger als 10 Minuten warten; fehlgeschlagene Mails unter
  `php artisan queue:failed` (erneut senden: `queue:retry`).

## 6. E-Mail-Zustellung

Die App verschickt Einladungen, Passwort-Links, Zwei-Faktor- und Ablauf-Hinweise -- landen
sie im Spam, kommen Lernende nicht in ihr Konto. Deshalb:

- **Transaktionalen Mailanbieter** nutzen statt des Server-eigenen Postfix oder eines
  normalen Postfachs: Standort/Verarbeitung in der EU, Auftragsverarbeitungsvertrag (AVV)
  abschließen, z. B. Brevo, Mailjet, Postmark (EU-Region) oder Amazon SES (eu-central-1).
  Zugang per SMTP in `.env` (`MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT=587`,
  `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`).
- **Eigene Absender-Domain**, z. B. `MAIL_FROM_ADDRESS=noreply@mail.example.de`. Eine
  Subdomain (`mail.`) trennt den Ruf der App-Mails von den Büro-Postfächern der Domain.
- **DNS-Einträge** für die Absender-Domain (Werte liefert der Anbieter; Beispiel):

  ```text
  ; SPF: nur der Mailanbieter darf für mail.example.de senden
  mail.example.de.                     TXT   "v=spf1 include:spf.anbieter.example ~all"
  ; DKIM: öffentlicher Schlüssel des Anbieters (Selector aus dessen Oberfläche)
  s1._domainkey.mail.example.de.       CNAME s1.dkim.anbieter.example.
  ; DMARC: erst beobachten (p=none), nach 2–4 Wochen sauberer Berichte p=quarantine
  _dmarc.mail.example.de.              TXT   "v=DMARC1; p=none; rua=mailto:dmarc@example.de; adkim=s; aspf=r"
  ; Return-Path/Bounce-Domain beim Anbieter (für SPF-Alignment)
  bounce.mail.example.de.              CNAME bounces.anbieter.example.
  ```

  Pro Domain nur **ein** SPF-Eintrag (mehrere `v=spf1` machen ihn ungültig). Den DKIM-Schlüssel
  (mind. 2048 Bit) signiert der Anbieter; in seiner Oberfläche muss die Domain als
  „verifiziert“ erscheinen.
- **Return-Path**: Der Anbieter setzt den Envelope-Absender (Return-Path) auf seine bzw. die
  oben eingetragene Bounce-Domain -- dort laufen Unzustellbarkeitsmeldungen auf, nicht im
  Postfach von `MAIL_FROM_ADDRESS`. Eine eigene Bounce-Subdomain (CNAME) sorgt dafür, dass SPF
  zur Absender-Domain passt (DMARC-Alignment).
- **Bounces und Beschwerden**: Im Anbieter Hard Bounces und Spam-Beschwerden automatisch
  sperren lassen (Suppression-Liste) und die Bounce-Benachrichtigung an eine betreute Adresse
  (z. B. `support@…`) schicken. Betroffene Konten im Superadmin prüfen (Tippfehler in der
  Adresse), statt immer wieder an dieselbe ungültige Adresse zu senden.
- **Prüfen**: Testmail an ein Gmail- und ein GMX/Web.de-Postfach schicken und im Quelltext
  `spf=pass`, `dkim=pass`, `dmarc=pass` kontrollieren (oder mail-tester.com). Die
  DMARC-Berichte (`rua`) in den ersten Wochen lesen, bevor `p=quarantine` gesetzt wird.
- **Reverse DNS** ist nur nötig, wenn der Server selbst versendet (nicht empfohlen).

## 7. Datenschutz im Betrieb

**Datenexport einer Schule** (Vertragsende): der Download im Superadmin-Bereich wird gestreamt,
unterliegt aber dem Zeitlimit von PHP-FPM (`request_terminate_timeout` 300 s, einschließlich der
Übertragung). Gemessen (Testsystem, je Mitglied 200 Fortschrittszeilen und 600 Lernversuche):
rund 30 ms und 25 Abfragen je Mitglied, etwa 0,2 MB JSON je Mitglied -- 300 Mitglieder ≈ 9 s und
58 MB. Ab einigen tausend Mitgliedern bzw. bei langsamer Verbindung deshalb auf dem Server
exportieren: `php artisan app:export-tenant <slug> /pfad/export.json` (gleicher Inhalt, ohne
Zeitlimit, Datei nur für den Betreiber lesbar; nach der Übergabe löschen).

Auftragsverarbeitungsverträge mit Hoster, Mail-, Speicher-, Video- und Fehlertracking-Anbieter
abschließen; Standorte möglichst in der EU. Die Datenschutzerklärung (Superadmin → Website)
muss die eingesetzten Anbieter nennen: Videoanbieter, externer Sicherungsspeicher
und Fehleranalyse erscheinen dort automatisch, wenn `PRIVACY_VIDEO_PROVIDER`,
`BACKUP_DISK`/`PRIVACY_BACKUP_PROVIDER` bzw. `SENTRY_LARAVEL_DSN` gesetzt sind
(`config/privacy.php`). Entwürfe für AVV, TOM, Verzeichnis der
Verarbeitungstätigkeiten, Löschkonzept, AGB und Barrierefreiheit:
`docs/recht/`. Löschfrist für inaktive Lernende:
`DATA_RETENTION_INACTIVE_LEARNER_MONTHS` (Standard aus).

## 8. Staging-Server

Ein zweiter Server (oder eine zweite App auf demselben Server mit eigener Datenbank,
eigenen DB-Rollen, eigener Domain wie `staging.lernen.example.de`) zum Ausprobieren von
Branches vor der Produktion.

1. **Einrichten** wie in Abschnitt 1, mit `APP_ENV=staging`, eigener `APP_KEY`, eigener
   Domain und eigener Datenbank. Mails nicht an echte Empfänger: `MAIL_MAILER=log` oder ein
   Test-Postfach-Dienst (z. B. Mailpit, Mailtrap). Shop-Anbindung aus (`COMMERCE_SHOP_URL`
   leer), `BACKUP_DISK` leer oder ein eigener Bucket, `SENTRY_ENVIRONMENT=staging`.
   Empfehlung: Modus `releases` (Abschnitt 2), dann sind auch Branches mit umgeschriebener
   Historie (force-push) problemlos ausrollbar; im Modus `inplace` braucht es ein
   Fast-Forward.
2. **GitHub**: Umgebung „staging“ anlegen (*Settings → Environments*) mit eigenen Secrets
   `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`, `DEPLOY_PATH`.
   Ausrollen: *Actions → Deploy → Run workflow*, oben den Branch wählen, „environment“ =
   `staging`. Voraussetzung: „Tests“ ist für genau diesen Commit grün.
3. **Daten** (optional, realistische Datenmenge): Dump der Produktion einspielen und
   **sofort anonymisieren** -- personenbezogene Daten gehören nicht auf Staging:

   ```bash
   # Dump der letzten Sicherung (ggf. vorher entschlüsseln, Abschnitt 3) verschlüsselt
   # (scp/rsync über SSH) auf den Staging-Server kopieren. Auf einem eigenen Server mit
   # denselben Rollennamen wie in der Produktion: Datenbank neu anlegen und einspielen
   # genau wie unter „Backup zurückspielen“ (Abschnitt 3). Andere Rollennamen (z. B.
   # zweite App auf demselben Server): ohne Eigentümer/Rechte einspielen und die Rechte
   # der App-Rolle danach wie im Haupt-README („Zwei DB-Rollen anlegen“) vergeben:
   #   pg_restore -h 127.0.0.1 -U boatingschool_staging -d boatingschool_staging \
   #     --no-owner --no-privileges database.dump
   php artisan migrate --database=pgsql_admin --force   # falls der Branch neuer ist
   php artisan app:anonymize-database --force
   php artisan app:create-superadmin du@example.de
   rm database.dump
   ```

   `app:anonymize-database` läuft nie mit `APP_ENV=production` und nur mit `--force`. Es
   ersetzt E-Mail-Adressen (`nutzer-<id>@example.invalid`), Namen, Anschriften, Geburtsdaten
   und Telefonnummern durch erfundene Werte, macht alle Passwörter, Zwei-Faktor-Schlüssel,
   Sitzungen und Tokens ungültig, leert Freitexte, Formulardaten und Protokoll-Inhalte,
   entfernt eigene Domains der Schulen (sonst würde Staging Zertifikate für deren echte
   Domains anfordern) und leert Warteschlangen und den Shop-Ausgang. Anmelden danach nur mit
   einem neu angelegten Superadmin (und von dort „Als Nutzer ansehen“).
   Der Dump bleibt eine Kopie echter Daten, bis er anonymisiert ist: verschlüsselt
   übertragen, danach löschen, und den Staging-Server nicht öffentlich ohne Schutz
   (z. B. HTTP-Basic-Auth im Webserver oder IP-Freigabe) betreiben.

## 9. Redis (optional, ab etwa 20 aktiven Schulen)

Standardmäßig laufen Cache (`CACHE_STORE`), Sitzungen (`SESSION_DRIVER`) und Queue
(`QUEUE_CONNECTION`) über die Datenbank -- ohne zusätzlichen Dienst und für kleinere
Installationen ausreichend. Abgelaufene Einträge des Datenbank-Caches räumt der Scheduler
täglich um 04:20 auf (`prune-expired-cache`). Mit wachsender Zahl an Schulen entlastet Redis
die Datenbank; `app:check-production` weist ab mehr als 20 aktiven Schulen darauf hin, wenn
alle drei noch über die Datenbank laufen. Die App braucht dafür keine Codeänderung:

```bash
sudo apt install redis-server php8.3-redis       # phpredis-Erweiterung (empfohlen)
# alternativ ohne Erweiterung: composer require predis/predis und REDIS_CLIENT=predis
```

`.env`:

```dotenv
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=<langes Zufallspasswort, in redis.conf: requirepass>
REDIS_PORT=6379
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

Danach `php artisan optimize`, `php artisan queue:restart` und im Worker
(`supervisor-worker.conf`) `queue:work database` durch `queue:work redis` ersetzen. Vorher
leer laufen lassen: Jobs, die noch in der Tabelle `jobs` warten, verarbeitet ein Worker mit
`queue:work database --stop-when-empty`. Beim Umstellen der Sitzungen werden alle Nutzer
einmal abgemeldet. Redis nur lokal binden (`bind 127.0.0.1 ::1`, Standard) und mit Passwort;
Persistenz (`appendonly yes`) einschalten, damit wartende Jobs einen Neustart überleben.
Die atomaren Sperren des Schedulers (`onOneServer`, `withoutOverlapping`) nutzen dann
ebenfalls Redis.

## 10. App-Deep-Links (Lernenden-App)

Damit Links aus Mails (Kontolöschung aus der App, Passwort festlegen/zurücksetzen, Einladung
annehmen, E-Mail bestätigen) direkt in der App öffnen, liefert die Plattform ohne Weiterleitung
und als JSON (`Cache-Control: public, max-age=3600`):

- `/.well-known/apple-app-site-association` (iOS Universal Links), wenn `MOBILE_API_IOS_APP_IDS`
  gesetzt ist: `<Team-ID>.<Bundle-ID>`, mehrere kommagetrennt. Die Pfade kommen aus
  `MOBILE_API_APP_LINK_PATHS` (kommagetrennt, `*` als Platzhalter). Leer gilt der Standard:
  `MOBILE_API_DELETION_CONFIRMATION_PATH` (Standard `/app/konto-loeschen`), `/reset-password/*`
  (Passwort festlegen nach Einladung und zurücksetzen), `/invitations/*/accept/*` (Einladung
  eines bestehenden Kontos) und `/verify-email/*/*` (E-Mail-Bestätigung). Jeder Pfad außer der
  Kontolöschung steht zusätzlich mit Schul-Slug davor (`/*/reset-password/*` usw.,
  Pfad-Modus `/<slug>/…`).
- `/.well-known/assetlinks.json` (Android App Links), wenn `MOBILE_API_ANDROID_PACKAGE` und
  `MOBILE_API_ANDROID_SHA256_FINGERPRINTS` gesetzt sind (SHA-256-Fingerabdrücke des
  Signaturzertifikats aus der Play Console, App-Signatur und ggf. Upload-Schlüssel,
  kommagetrennt). Die Datei nennt keine Pfade: dieselben Pfade gehören in den Intent-Filter
  der App (`android:autoVerify="true"`, `pathPattern`/`pathAdvancedPattern`).

**Auf welchen Hosts:** auf der zentralen Domain und nur bei `TENANT_URL_MODE=subdomain` auch auf
den Subdomains der Schulen (`<slug>.lernen.example.de`, ohne den Pfad der Kontolöschung). Auf
eigenen Domains der Schulen nicht: alle diese Mails verlinken immer auf die zentrale Domain bzw.
die Schul-Subdomain (`CanonicalUrl`), nie auf die eigene Domain. Die App braucht entsprechend
die Associated Domain `applinks:lernen.example.de` (im Subdomain-Modus zusätzlich
`applinks:*.lernen.example.de`) bzw. passende Hosts im Intent-Filter.

Ohne diese Werte antworten beide Adressen mit 404, die Links öffnen dann immer die Webseite.
Wer die Dateien statisch ausliefert (Webserver/CDN), lässt die Variablen leer. Nach Änderungen
`php artisan optimize`. iOS lädt die Datei über Apples CDN und speichert sie zwischen --
Änderungen wirken dort erst nach Stunden bis Tagen. Prüfen:
`curl -i https://lernen.example.de/.well-known/assetlinks.json`
(Status 200, `Content-Type: application/json`, keine Weiterleitung).
