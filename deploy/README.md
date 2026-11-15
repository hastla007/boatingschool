# Betriebshandbuch: Server, Deploy, Backup

Vorlagen in diesem Ordner gehen von einem Linux-Server (Ubuntu/Debian) aus,
mit der App unter `/var/www/boatingschool`, einem Deploy-Benutzer `deploy`
und PHP 8.3-FPM. Pfade, Domain (`lernen.example.de`) und Benutzer anpassen.
Mit Laravel Forge oder Ploi gelten dieselben Schritte; dort trägt man Cron,
Worker und Deploy-Skript in der Oberfläche ein.

| Datei | Zweck |
|---|---|
| `deploy.sh` | Update auf dem Server (Sperre, Abhängigkeiten/Build vorab, Wartungsmodus, Sicherung, Migrationen, Caches, Go-live-Check) |
| `Caddyfile` | Webserver mit automatischen Zertifikaten, auch für eigene Domains der Schulen (empfohlen) |
| `nginx.conf` | Alternative mit nginx und Wildcard-Zertifikat |
| `php-fpm-pool.conf` | PHP-FPM-Pool (Benutzer, Limits, nur `.php` ausführen) |
| `crontab` | Scheduler (jede Minute, Ausgabe nach `storage/logs/scheduler.log`) |
| `supervisor-worker.conf` | Queue-Worker (Pflicht: Massen-Mails laufen über die Queue) |
| `../.github/workflows/deploy.yml` | Deploy per Knopfdruck aus GitHub (optional) |

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
   nur im Session-Modus.
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
9. **Queue-Worker**: `supervisor-worker.conf` nach `/etc/supervisor/conf.d/boatingschool-worker.conf`,
   dann `supervisorctl reread && supervisorctl update`. Pflicht: Mitteilungen per Mail,
   Sammel-Einladungen, Sammel-Verlängerungen, Lern-Erinnerungen an viele und Lerngruppen-
   Einladungen werden nur eingereiht und erst vom Worker verschickt. `deploy.sh` startet ihn
   nach jedem Update neu (`queue:restart`).
10. **Prüfen**: `php artisan app:check-production` bzw. im Superadmin unter „System“.
   Nach einigen Minuten müssen Scheduler, Queue-Worker (Lebenszeichen) und (am nächsten
   Morgen) Datensicherung grün sein; ein fälliger Job, der länger als 10 Minuten wartet,
   gilt als hängend.

## 2. Updates einspielen

```bash
cd /var/www/boatingschool && ./deploy/deploy.sh          # Branch main (zieht vorher eine Sicherung)
```

Oder in GitHub unter *Actions → Deploy → Run workflow* (Secrets siehe `deploy.yml`). Der
Workflow rollt nur `main` aus, und nur einen Commit, für den „Tests“ grün ist.

Ablauf: Eine Sperre (`flock` auf `.deploy/lock`) verhindert zwei gleichzeitige Deploys.
Der neue Stand wird zuerst nur geholt; Composer-Abhängigkeiten und Assets entstehen in
einem Vorbereitungsordner (`.deploy/staging`, ein `git worktree`), während die Seite
weiterläuft. Erst dann folgen Wartungsmodus, Sicherung (`app:backup --deploy`),
Umschalten (Code, `vendor/` und `public/build` per Umbenennen), Migrationen, Seeder und
Caches -- die Wartungszeit besteht im Wesentlichen aus Sicherung und Migrationen.

Schlägt ein Schritt vor dem Wartungsmodus fehl, läuft die Seite unverändert weiter. Danach
bleibt sie im Wartungsmodus; das Skript gibt den vorherigen Commit aus. Ursache beheben und
das Skript erneut starten, zurück ohne Update mit `php artisan up` (nur wenn noch nicht
umgeschaltet wurde) oder per Rückroll.

### Rückroll

Ist das Update eingespielt, aber fehlerhaft (oder bricht es nach dem Umschalten ab):

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
den dann korrigierten Stand ein.

Das Skript spielt auch Inhaltsupdates ein (`db:seed`, idempotent; Korrekturen aus dem
Superadmin bleiben erhalten). Darf der Deploy-Benutzer PHP-FPM neu laden, `PHP_FPM_SERVICE=php8.3-fpm`
setzen (leert den Opcache).

Migrationen sind nur vorwärts gedacht. Das Skript zieht deshalb vor jedem Update eine
Sicherung (`php artisan app:backup --deploy`: nur lokal, Ordner `<Zeitstempel>-deploy`, es
bleiben die letzten `BACKUP_KEEP_DEPLOY`, Standard 3); schlägt sie fehl, bricht der Deploy ab.

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
zusätzlich nach außen; vorher `composer require league/flysystem-aws-s3-v3`. Aufbewahrung:
`BACKUP_KEEP_DAYS` (Standard 14). Videos liegen beim Videoanbieter und sind nicht Teil der Sicherung.
Schlägt nur die externe Kopie fehl, bleibt die lokale Sicherung erhalten; der Lauf endet
trotzdem mit Fehler (Betriebsalarm an `OPS_ALERT_EMAIL`), und der Go-live-Check meldet
„Externe Sicherung erfolgreich“ als Fehler, bis wieder eine Kopie vollständig ist.

**Verschlüsselung (dringend empfohlen):** Mit `BACKUP_ENCRYPTION_PUBLIC_KEY` werden alle
Dateien außer `manifest.json` vor dem Hochladen verschlüsselt (`*.enc`; libsodium:
X25519-versiegelter Schlüssel je Datei, XChaCha20-Poly1305 im Datenstrom, Manipulation
und abgeschnittene Dateien werden erkannt). Lokal bleiben die Dateien unverschlüsselt.

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

- Bucket mit Versionierung und Object Lock (Modus „Compliance“ oder „Governance“, z. B. 30 Tage)
  anlegen und eine Lifecycle-Regel setzen, die `backups/` nach `BACKUP_KEEP_DAYS` (plus Sperrfrist)
  löscht. Dann `BACKUP_PRUNE_REMOTE=false`: `app:backup` löscht extern nichts mehr.
- Zugangsschlüssel des Servers nur mit Schreibrecht auf `backups/*` (`s3:PutObject`); ohne
  `s3:DeleteObject` und ohne Rechte zum Ändern von Bucket-Einstellungen. Mit
  `BACKUP_PRUNE_REMOTE=true` (Standard) braucht er zusätzlich `s3:ListBucket` und `s3:DeleteObject`.
- Optional `OPS_BACKUP_HEARTBEAT_URL`: Ping an einen Heartbeat-Dienst nach jeder erfolgreichen
  täglichen Sicherung (bleibt er aus, alarmiert der Dienst).

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
PGPASSWORD=… pg_restore -h 127.0.0.1 -U boatingschool -d boatingschool --exit-on-error storage/app/backups/<Zeitstempel>/database.dump
# Uploads
unzip -o storage/app/backups/<Zeitstempel>/uploads.zip -d storage/app/public
unzip -o storage/app/backups/<Zeitstempel>/protected-media.zip -d storage/app/private   # falls vorhanden
php artisan optimize:clear && php artisan optimize && php artisan up
```

**Einmal vor dem Start ausprobieren** (z. B. auf einem Testserver), damit im Ernstfall jeder
Schritt sitzt.

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

- **Queue**: `app:check-production` meldet einen Worker ohne Lebenszeichen (über 5 Minuten)
  und fällige Jobs, die länger als 10 Minuten warten; fehlgeschlagene Mails unter
  `php artisan queue:failed` (erneut senden: `queue:retry`).

## 6. Datenschutz im Betrieb

**Datenexport einer Schule** (Vertragsende): der Download im Superadmin-Bereich wird gestreamt,
unterliegt aber dem Zeitlimit von PHP-FPM (`request_terminate_timeout` 300 s, einschließlich der
Übertragung). Gemessen (Testsystem, je Mitglied 200 Fortschrittszeilen und 600 Lernversuche):
rund 30 ms und 25 Abfragen je Mitglied, etwa 0,2 MB JSON je Mitglied -- 300 Mitglieder ≈ 9 s und
58 MB. Ab einigen tausend Mitgliedern bzw. bei langsamer Verbindung deshalb auf dem Server
exportieren: `php artisan app:export-tenant <slug> /pfad/export.json` (gleicher Inhalt, ohne
Zeitlimit, Datei nur für den Betreiber lesbar; nach der Übergabe löschen).

Auftragsverarbeitungsverträge mit Hoster, Mail-, Speicher-, Video- und Fehlertracking-Anbieter
abschließen; Standorte möglichst in der EU. Die Datenschutzerklärung (Superadmin → Website)
muss die eingesetzten Anbieter nennen.
