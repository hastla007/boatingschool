> **ENTWURF – vor Verwendung anwaltlich prüfen lassen; keine Rechtsberatung**

# Technische und organisatorische Maßnahmen (Art. 32 DSGVO)

Anlage 2 zum Auftragsverarbeitungsvertrag ([avv-vorlage.md](avv-vorlage.md)).
Beschrieben ist, was die Software der Plattform **tatsächlich umsetzt** (mit
Fundstelle im Code). Maßnahmen, die beim Betrieb bzw. beim Hosting liegen und
im Code nicht nachweisbar sind, sind mit **offen** markiert und vom Betreiber
mit dem Hosting-Anbieter zu belegen.

Betreiber: [Betreiber], [Anschrift]
Hosting: [Hosting-Anbieter], Rechenzentrum [Standort, EU]
Stand: [Datum]

## 1. Vertraulichkeit

### 1.1 Zutrittskontrolle (Rechenzentrum)

- **offen**: Physische Sicherung des Rechenzentrums – Nachweis des
  Hosting-Anbieters (z. B. ISO/IEC 27001-Zertifikat) beifügen.
- Büroräume des Betreibers: [Maßnahmen] – **offen**.

### 1.2 Zugangskontrolle (Systeme)

- Anmeldung nur mit E-Mail und Passwort; **Passwortregel** mindestens 10
  Zeichen, in Produktion zusätzlich Abgleich gegen bekannte geleakte Passwörter
  (Have I Been Pwned, k-Anonymität: nur die ersten 5 Zeichen des SHA-1-Hashes
  verlassen den Server) – `AppServiceProvider::configurePasswordPolicy()`.
- Passwörter nur als bcrypt-Hash gespeichert.
- **Zwei-Faktor-Anmeldung (TOTP)** für alle Konten verfügbar; für Superadmins
  in Produktion standardmäßig Pflicht (`SUPERADMIN_REQUIRE_TWO_FACTOR`,
  `EnsureSuperAdmin`), für das Team
  einer Schule per Schalter des Inhabers erzwingbar (`EnforceTeamTwoFactor`).
  2FA-Geheimnisse und Wiederherstellungscodes verschlüsselt (APP_KEY).
- **Begrenzung von Anmeldeversuchen** (Login, Registrierung, Passwort
  vergessen: je 10/Minute und IP; 2FA-Codes 5 Versuche in 5 Minuten; App-API
  eigene Grenzen) – `routes/auth.php`, `routes/api.php`.
- **Hinweis-Mail bei Anmeldung von einem neuen Gerät** für Team-Konten und
  Superadmins (`NewLoginDeviceNotifier`; gespeichert wird nur ein HMAC aus
  Browserfamilie, Betriebssystem und gekürzter IP).
- Sitzungs-Cookies `HttpOnly`, `Secure` (Produktion), `SameSite=Lax`; je Schule
  eigener Cookie-Pfad bzw. eigene Subdomain.
- App-Tokens: zufällig (> 256 Bit), nur als SHA-256-Hash gespeichert, kurzlebige
  Access-Tokens (15 Min.), rotierende Refresh-Tokens mit Wiederverwendungs­erkennung
  (`App\Api\DeviceSessions`).
- Kontosperre wirkt sofort (Status wird bei jeder Anfrage geprüft,
  `EnsureAccountIsActive`), Passwortwechsel beendet alle App-Sitzungen.
- **Anmeldung der Webansicht aus der App** nur mit Einmal-Code (60 Sekunden,
  einmalig, nur als Hash gespeichert, an Konto, Gerätesitzung und Schule
  gebunden) plus Verifier im Header – die Adresse allein (Verlauf, Logs)
  genügt nicht; nur Ziele im Lernbereich, nicht für Konten mit
  Verwaltungsrechten, abschaltbar (`MOBILE_API_WEB_SESSION_ENABLED`,
  `WebSessionController`, `ConsumeMobileWebSession`).
- **Kontolöschung aus der App** nur nach Bestätigung über einen Link per Mail
  (Schlüssel nur als SHA-256-Hash, 7 Tage gültig, Versuche je IP und Antrag
  begrenzt, `AppAccountDeletion`); Antworten verraten nicht, ob ein Antrag
  existiert.
- **offen**: SSH-Zugang zum Server nur mit Schlüssel, Liste der Personen mit
  Serverzugang, Passwortmanager für Betriebsgeheimnisse (`APP_KEY`,
  Datenbank-, Mail-, S3-Zugänge, siehe `deploy/README.md` Abschnitt 3).

### 1.3 Zugriffskontrolle (Berechtigungen)

- **Mandantentrennung in der Datenbank (Row Level Security)**: Jede
  schulbezogene Tabelle hat PostgreSQL-RLS-Policies; die Anwendung arbeitet mit
  einer eigenen Datenbankrolle ohne RLS-Umgehung und setzt je Anfrage den
  Mandanten (`ResolveTenant`, `app.current_tenant_id`). Eine Schule kann die
  Daten einer anderen auch bei einem Programmierfehler nicht lesen.
- Die RLS-freie Owner-Verbindung ist auf freigegebene Stellen beschränkt
  (Allowlist mit Begründung, automatischer Architekturtest
  `tests/Unit/OwnerConnectionUsageTest.php`, `docs/architektur/owner-verbindung.md`).
- **Rollen** je Schule: Inhaber, Admin, Ausbilder (nur zugeordnete Kurse,
  `EnforceInstructorScope`), Lernende; plattformweit Superadmin.
- **Support-Ansicht („Als Nutzer ansehen“)** nur für Superadmins, nur lesend
  (Schreibversuche werden abgelehnt und protokolliert), endet automatisch nach
  60 Minuten, Start/Ende/Ablauf werden protokolliert (`RestrictImpersonation`,
  `App\Support\Impersonation`).
- Dateien mit Personenbezug (Datenexporte, Kartenausschnitte der
  Navigationsaufgaben) liegen auf dem privaten Speicher, Download nur über
  signierte, kurzlebige Links.
- **Geschützte Medien** (lizenzierte Fragen- und Praxistrainer-Bilder,
  Kartenausschnitte, Tipp-PDFs, Bilder der Videoschritte) liegen
  auf dem privaten Speicher und werden nur über signierte Adressen mit
  Ablaufzeit (90–120 Minuten) ausgeliefert, die an Konto und Schule gebunden
  sind; bei jedem Abruf wird die Mitgliedschaft bzw. der Kurszugang erneut
  geprüft (`App\Support\ProtectedMedia`, `QuestionMedia`, `NavigationChart`,
  `TipPdf`, `VideoStepImage`). Adressen werden nie zwischengespeichert;
  Antworten mit restriktiver Content-Security-Policy (Bilder mit `sandbox`).
- **Lerngruppen**: Der Verwalter sieht nur die eigenen Gruppen und dort nur
  Namen, Einladungsadressen und Status der Mitglieder, keine Lerndaten;
  Mitglied und Verwalter müssen derselben Schule angehören (RLS und
  zusammengesetzte Fremdschlüssel); Antworten auf Einladungen verraten nicht,
  ob es zu einer Adresse ein Konto gibt (`LearningGroupService`).
- Downloads von Kursdateien nur mit Kurszugang bzw. freigeschalteter Funktion
  (`App\Api\CourseFiles`, `GET /api/v1/files/{id}`).

### 1.4 Trennungskontrolle

- Logische Trennung je Bootsschule durch RLS (siehe 1.3); Kontolöschung wirkt je
  Schule getrennt (`AccountErasureService::leaveTenant`).
- Getrennte Datenbanken für Test und Produktion (Tests laufen nie gegen die
  Produktionsdatenbank).
- **offen**: Trennung von Entwicklungs- und Produktionszugängen organisatorisch
  dokumentieren.

### 1.5 Pseudonymisierung / Datenminimierung

- Fehlerberichte (optional Sentry) ohne personenbezogene Daten:
  `send_default_pii = false`, keine Request-Inhalte, keine SQL-Parameter,
  Tokens/E-Mail-Adressen werden vorher entfernt (`config/sentry.php`,
  `App\Support\SentryScrubber`).
- Protokolleinträge werden bei Kontolöschung pseudonymisiert (Aktion und
  Zeitpunkt bleiben, Inhalte mit Personenbezug werden entfernt).
- Gerätewiedererkennung nur als HMAC, IP-Adressen in Hinweis-Mails maskiert.
- Codewerte aus dem Shop verlassen die Plattform nie (keine Codes in Mails,
  Protokollen oder Shop-Ereignissen, `docs/commerce/README.md`).
- **Zugriffsprotokolle des Webservers ohne Geheimnisse**: ohne Query-String
  (E-Mail-Adressen, Signaturen, Codes, Token) bzw. mit unkenntlich gemachten
  Werten, Token im Pfad (Passwort-Reset, Support-Ansicht, Gruppeneinladung)
  ersetzt, Referer gekürzt bzw. entfernt, Aufbewahrung 14 Tage
  (`deploy/nginx.conf`, `deploy/Caddyfile`).
- Mails mit Einladungscodes bzw. Einladungslinks (Kontoeinladung,
  Lerngruppen-Einladung) liegen nur verschlüsselt in der Warteschlange
  (`ShouldBeEncrypted`, APP_KEY).
- Keine Analyse- oder Werbe-Cookies, kein Tracking, Schriften vom eigenen Server.

## 2. Integrität

### 2.1 Weitergabekontrolle

- **Verschlüsselung bei der Übertragung**: HTTPS mit automatischen Zertifikaten
  (Caddy) bzw. certbot (nginx), HSTS (`SecurityHeaders`), Datenbankverbindung zu
  einem entfernten Host mit `DB_SSLMODE=verify-full` (`deploy/README.md`),
  Mailversand per SMTP mit STARTTLS (Port 587).
- Shop-Anbindung nur mit HMAC-signierten Anfragen und Zeitstempelprüfung
  (`VerifyCommerceSignature`).
- Sicherheits-Header: Content-Security-Policy mit Nonces, `X-Frame-Options: DENY`,
  `nosniff`, `Referrer-Policy`, `Permissions-Policy` (Mikrofon nur für die eigene
  Seite).
- **Gehärtete Webserver-Konfiguration** (`deploy/Caddyfile`, `deploy/nginx.conf`,
  `deploy/php-fpm-pool.conf`): nur `public/index.php` läuft über PHP-FPM, jede
  andere `.php`-Adresse ergibt 404; Uploads (`/storage`) und Assets gehen nie
  an PHP, alle Antworten tragen `nosniff`, Uploads zusätzlich eine Sandbox-CSP
  (kein Skript etwa aus einer hochgeladenen SVG-Datei); PHP-FPM führt nur Dateien mit
  der Endung `.php` aus (`security.limit_extensions`).

### 2.2 Eingabekontrolle (Protokollierung)

- **Änderungsprotokoll (`audit_log`)**: Änderungen durch Schul-Team und
  Superadmin (Teilnehmer, Freischaltungen, Codes, Termine, Einstellungen …), jede
  schreibende Aktion im Superadmin-Bereich automatisch
  (`AuditSuperadminChanges`), Support-Ansicht mit tatsächlichem Akteur
  (Superadmin) statt angesehenem Konto.
- Datenexporte über die App werden protokolliert.
- Server-Protokolle 14 Tage (`LOG_DAILY_DAYS`).
- **offen**: Protokollierung administrativer Zugriffe auf Server und Datenbank
  (Shell, `psql`) beim Hosting.

## 3. Verfügbarkeit und Belastbarkeit

- **Datensicherung** täglich 03:15 Uhr: vollständiger Datenbank-Dump, alle
  Uploads und die geschützten Dateien des privaten Speichers, Prüfsummen im
  Manifest (`app:backup`, `config/backup.php`), Aufbewahrung **14 Tage**
  (`BACKUP_KEEP_DAYS`), optional Kopie auf externen S3-Speicher
  (`BACKUP_DISK`). Vor jedem Update automatische Sicherung.
- **Verschlüsselung der externen Sicherungen** (umgesetzt): Mit
  `BACKUP_ENCRYPTION_PUBLIC_KEY` verschlüsselt `app:backup` jede Datei vor dem
  Hochladen clientseitig mit libsodium (XChaCha20-Poly1305 als Datenstrom,
  jeder Block authentisiert; Dateischlüssel versiegelt mit dem öffentlichen
  X25519-Schlüssel, `App\Support\BackupEncryption`). Der Server kennt nur den
  öffentlichen Schlüssel und kann die externen Kopien selbst nicht lesen; der
  geheime Schlüssel liegt außerhalb des Servers (Passwortmanager/Tresor),
  Entschlüsselung mit `app:backup-decrypt`, Schlüsselpaar mit
  `app:backup-keygen`. Der Go-live-Check warnt bei unverschlüsselter externer
  Kopie. Optional löscht der Server alte externe Sicherungen nicht selbst
  (`BACKUP_PRUNE_REMOTE=false`, Zugang nur schreibend, Object Lock/Lifecycle
  beim Speicher). Die Datenschutzerklärung nennt die Verschlüsselung
  automatisch (`PRIVACY_BACKUPS_ENCRYPTED` nur zum Überschreiben).
- **Wiederherstellungstest** automatisiert: monatlich spielt
  `app:backup:verify` die neueste Sicherung in eine temporäre Datenbank zurück
  und prüft Pflichttabellen (`BACKUP_VERIFY_TABLES`), Mail bei Fehler.
- **Überwachung**: täglicher Go-live-Check mit Mail bei Fehlern (Scheduler
  steht, Sicherung fehlt oder zu alt, Queue-Worker ohne Lebenszeichen, Jobs
  warten zu lange, Platzhalter), Lebenszeichen von Scheduler und Queue-Worker,
  Healthcheck `/up` mit Datenbankprüfung; **externer Totmann-Schalter**: der
  Scheduler pingt jede Minute `OPS_HEARTBEAT_URL`, jede erfolgreiche Sicherung
  `OPS_BACKUP_HEARTBEAT_URL` (z. B. healthchecks.io) – bleibt der Ping aus,
  alarmiert der externe Dienst (übermittelt werden keine personenbezogenen
  Daten).
- **Queue-Worker** (`deploy/supervisor-worker.conf`, von supervisor überwacht
  und neu gestartet): Sammel-Mails werden eingereiht und mit Wiederholung
  verschickt; nach jedem Deploy Neustart mit dem neuen Code.
- Geplante Aufgaben laufen nur auf einem Server und nicht überlappend; die
  Ausgabe des Schedulers wird protokolliert (`deploy/crontab`).
- **offen**: lokale Sicherungen auf dem Server sind nicht verschlüsselt
  (Schutz über Dateirechte und ggf. Datenträgerverschlüsselung beim Hosting);
  Verwahrung und Zugriffsberechtigte für den geheimen Sicherungsschlüssel
  dokumentieren; Notfallplan, USV/Redundanz beim Hosting.
- **offen**: Verschlüsselung ruhender Daten (Festplatten-/Volume-Verschlüsselung
  beim Hosting); die Anwendung verschlüsselt nur 2FA-Geheimnisse, nicht die
  gesamte Datenbank.
- **offen**: `SESSION_ENCRYPT` ist standardmäßig aus (Sitzungsdaten liegen
  unverschlüsselt in der Datenbanktabelle `sessions`).

## 4. Verfahren zur regelmäßigen Überprüfung

- Automatisierte Tests (PHPUnit) inkl. Mandantentrennung, Löschung, Export, RLS
  vor jedem Deploy („Tests“-Workflow muss grün sein).
- Mehrere dokumentierte Sicherheits- und Datenschutz-Reviews (Kommentare
  „Sicherheitsreview“, „DSGVO-Review“ im Code).
- **Löschroutinen** (siehe [loeschkonzept.md](loeschkonzept.md)):
  `app:prune-personal-data` (täglich; Mobile-API stündlich, u. a. abgelaufene
  Lerngruppen-Einladungen, Löschanträge und Einmal-Codes der Webansicht),
  `app:prune-inactive-learners` (täglich, wenn Frist gesetzt).
- **Auskunft/Datenübertragbarkeit**: Export im Profil (Web) und in der App
  (inkl. Lerngruppen-Mitgliedschaften, verwalteter Gruppen und
  App-Einstellungen), Export einer ganzen Schule bei Vertragsende.
- **Abhängigkeiten**: Bekannte Sicherheitslücken in PHP- und JavaScript-Paketen
  prüft der „Tests“-Workflow (`composer audit`, `npm audit`); Hinweise werden
  zeitnah eingespielt (z. B. Laravel 12.72, CVE-2026-128083).
- **offen**: jährliche Überprüfung dieser TOM, Schulung der Mitarbeitenden,
  Verpflichtung auf Vertraulichkeit, Penetrationstest durch Dritte.

## 5. Auftragskontrolle

- Unterauftragsverarbeiter siehe Anlage 1 des AVV; Auswahl mit EU-Standort
  bevorzugt (`deploy/README.md` Abschnitt 6 und 7).
- **offen**: AVV mit allen Unterauftragsverarbeitern abschließen und ablegen.
