# Betriebshandbuch: Server, Deploy, Backup

Vorlagen in diesem Ordner gehen von einem Linux-Server (Ubuntu/Debian) aus,
mit der App unter `/var/www/boatingschool`, einem Deploy-Benutzer `deploy`
und PHP 8.3-FPM. Pfade, Domain (`lernen.example.de`) und Benutzer anpassen.
Mit Laravel Forge oder Ploi gelten dieselben Schritte; dort trägt man Cron,
Worker und Deploy-Skript in der Oberfläche ein.

| Datei | Zweck |
|---|---|
| `deploy.sh` | Update auf dem Server (Wartungsmodus, Code, Abhängigkeiten, Build, Migrationen, Caches, Go-live-Check) |
| `Caddyfile` | Webserver mit automatischen Zertifikaten, auch für eigene Domains der Schulen (empfohlen) |
| `nginx.conf` | Alternative mit nginx und Wildcard-Zertifikat |
| `crontab` | Scheduler (jede Minute) |
| `supervisor-worker.conf` | Queue-Worker (derzeit optional) |
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
   Mail-Zugang (SMTP des Mailanbieters), `MAIL_FROM_ADDRESS`, `BACKUP_DISK`, optional `SENTRY_LARAVEL_DSN`.
   `TRUSTED_PROXIES` leer lassen, außer ein Load-Balancer steht davor.
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
8. **Cron**: Zeile aus `crontab` mit `crontab -e` als Deploy-Benutzer eintragen.
9. **Prüfen**: `php artisan app:check-production` bzw. im Superadmin unter „System“.
   Nach einigen Minuten müssen Scheduler und (am nächsten Morgen) Datensicherung grün sein.

## 2. Updates einspielen

```bash
cd /var/www/boatingschool && ./deploy/deploy.sh          # Branch main (zieht vorher eine Sicherung)
```

Oder in GitHub unter *Actions → Deploy → Run workflow* (Secrets siehe `deploy.yml`). Der
Workflow rollt nur `main` aus, und nur einen Commit, für den „Tests“ grün ist.
Bei einem Fehler bleibt die Seite im Wartungsmodus; Ursache beheben und das Skript erneut
starten. Zurück ohne Update: `php artisan up`.

Das Skript spielt auch Inhaltsupdates ein (`db:seed`, idempotent; Korrekturen aus dem
Superadmin bleiben erhalten). Darf der Deploy-Benutzer PHP-FPM neu laden, `PHP_FPM_SERVICE=php8.3-fpm`
setzen (leert den Opcache).

Migrationen sind nur vorwärts gedacht. Das Skript zieht deshalb vor jedem Update eine
Sicherung (`php artisan app:backup`); schlägt sie fehl, bricht der Deploy ab.

## 3. Datensicherung

`app:backup` läuft täglich um 03:15 (Europe/Berlin) und legt unter `storage/app/backups/<Zeitstempel>/` ab:

- `database.dump`: kompletter Datenbank-Dump (pg_dump, Custom-Format, inkl. Rechte und RLS-Policies)
- `uploads.zip`: alle Uploads (Logos, Bilder, Kartenausschnitte, PDFs)
- `manifest.json`: Zeitpunkt, Größen, SHA-256-Prüfsummen

Mit `BACKUP_DISK=s3` (S3-kompatibler Speicher, z. B. Hetzner Object Storage) geht jede Sicherung
zusätzlich nach außen; vorher `composer require league/flysystem-aws-s3-v3`. Aufbewahrung:
`BACKUP_KEEP_DAYS` (Standard 14). Videos liegen beim Videoanbieter und sind nicht Teil der Sicherung.

### Backup zurückspielen

**Wichtig: `.env` gehört nicht in die Sicherung, wird aber gebraucht.** `APP_KEY`
verschlüsselt u. a. die Zwei-Faktor-Schlüssel und Wiederherstellungscodes aller
Konten (auch der Superadmins). Mit einem neuen `APP_KEY` (`key:generate`) lassen
sie sich nach dem Zurückspielen nicht mehr entschlüsseln. `APP_KEY` sowie die
Geheimnisse der Shop-Anbindung (`COMMERCE_*_SECRET`) und die Zugangsdaten für
Datenbank, Mail und S3 deshalb getrennt und sicher aufbewahren (z. B. im
Passwortmanager) und beim Zurückspielen wieder in `.env` eintragen.

Auf einem Server mit denselben Rollen (`boatingschool`, `boatingschool_app`):

```bash
php artisan down
# Datenbank neu anlegen (als postgres-Superuser)
sudo -u postgres psql -c "DROP DATABASE boatingschool" -c "CREATE DATABASE boatingschool OWNER boatingschool" \
  -c "GRANT CONNECT ON DATABASE boatingschool TO boatingschool_app"
PGPASSWORD=… pg_restore -h 127.0.0.1 -U boatingschool -d boatingschool --exit-on-error storage/app/backups/<Zeitstempel>/database.dump
# Uploads
unzip -o storage/app/backups/<Zeitstempel>/uploads.zip -d storage/app/public
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

## 5. Überwachung

- **Erreichbarkeit**: externen Uptime-Dienst (z. B. UptimeRobot, Better Stack) auf
  `https://lernen.example.de/up` richten (liefert 200, ohne Session/Datenbankzugriff).
- **Fehler**: `SENTRY_LARAVEL_DSN` setzen (EU-Standort), sonst nur `storage/logs/laravel-*.log`
  (14 Tage, `LOG_DAILY_DAYS`).
- **Go-live-Check**: `php artisan app:check-production` (Exit-Code 1 bei Fehlern, eignet sich
  für einen täglichen Cron mit Mail bei Fehlern) bzw. Superadmin → „System“.

## 6. Datenschutz im Betrieb

Auftragsverarbeitungsverträge mit Hoster, Mail-, Speicher-, Video- und Fehlertracking-Anbieter
abschließen; Standorte möglichst in der EU. Die Datenschutzerklärung (Superadmin → Website)
muss die eingesetzten Anbieter nennen.
