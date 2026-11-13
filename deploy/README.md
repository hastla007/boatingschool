# Betriebshandbuch: Server, Deploy, Backup

Vorlagen in diesem Ordner gehen von einem Linux-Server (Ubuntu/Debian) aus,
mit der App unter `/var/www/boatingschool`, einem Deploy-Benutzer `deploy`
und PHP 8.3-FPM. Pfade, Domain (`lernen.example.de`) und Benutzer anpassen.
Mit Laravel Forge oder Ploi gelten dieselben Schritte; dort trägt man Cron,
Worker und Deploy-Skript in der Oberfläche ein.

| Datei | Zweck |
|---|---|
| `deploy.sh` | Update auf dem Server (Wartungsmodus, Code, Abhängigkeiten, Assets, Migrationen, Inhalte bei Bedarf, Caches, Go-live-Check) |
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
   nur im Session-Modus. Läuft die Datenbank auf einem anderen Host, `DB_SSLMODE=verify-full`
   setzen (Standard `prefer` ist nur für lokale Verbindungen gedacht und prüft kein Zertifikat).
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
./deploy/deploy.sh main <commit> --with-content          # genau diesen Commit, Inhalte erzwingen
BUILD_DIR=/tmp/build ./deploy/deploy.sh                  # fertigen Vite-Build übernehmen
```

Oder in GitHub unter *Actions → Deploy → Run workflow* (Secrets siehe `deploy.yml`). Der
Workflow rollt nur `main` aus, und nur einen Commit, für den „Tests“ grün ist. Er baut die
Frontend-Assets für genau diesen Commit in einem eigenen Job (`npm ci && npm run build`),
kopiert `public/build` per `scp` in ein temporäres Verzeichnis auf dem Server und startet
`deploy.sh` mit `BUILD_DIR` -- im Wartungsmodus wird dann nicht mehr gebaut. Die Option
„with_content“ im Workflow entspricht `--with-content`.
Bei einem Fehler bleibt die Seite im Wartungsmodus; Ursache beheben und das Skript erneut
starten. Zurück ohne Update: `php artisan up`.

**Assets**: Ohne `BUILD_DIR` (bzw. `--build-dir DIR`) baut das Skript wie bisher auf dem
Server (Node nötig). Fehlt im Verzeichnis `manifest.json` oder gehört die beiliegende
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

Auftragsverarbeitungsverträge mit Hoster, Mail-, Speicher-, Video- und Fehlertracking-Anbieter
abschließen; Standorte möglichst in der EU. Die Datenschutzerklärung (Superadmin → Website)
muss die eingesetzten Anbieter nennen.
