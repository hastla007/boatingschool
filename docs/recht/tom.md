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

- **Datensicherung** täglich 03:15 Uhr: vollständiger Datenbank-Dump und alle
  Uploads, Prüfsummen im Manifest (`app:backup`, `config/backup.php`),
  Aufbewahrung **14 Tage** (`BACKUP_KEEP_DAYS`), optional Kopie auf externen
  S3-Speicher (`BACKUP_DISK`). Vor jedem Update automatische Sicherung.
- Überwachung: täglicher Go-live-Check mit Mail bei Fehlern (Scheduler steht,
  Sicherung fehlt oder zu alt, Platzhalter), Lebenszeichen des Schedulers,
  Healthcheck `/up` mit Datenbankprüfung.
- Geplante Aufgaben laufen nur auf einem Server und nicht überlappend.
- **offen**: Verschlüsselung der Sicherungen auf dem externen Speicher (derzeit
  nur, wenn der Speicher serverseitig verschlüsselt – in der
  Datenschutzerklärung über `PRIVACY_BACKUPS_ENCRYPTED` anzugeben),
  Wiederherstellungstest dokumentieren (`deploy/README.md` „Backup
  zurückspielen“), Notfallplan, USV/Redundanz beim Hosting.
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
  `app:prune-personal-data` (täglich; Mobile-API stündlich),
  `app:prune-inactive-learners` (täglich, wenn Frist gesetzt).
- **Auskunft/Datenübertragbarkeit**: Export im Profil (Web) und in der App,
  Export einer ganzen Schule bei Vertragsende.
- **offen**: jährliche Überprüfung dieser TOM, Schulung der Mitarbeitenden,
  Verpflichtung auf Vertraulichkeit, Penetrationstest durch Dritte.

## 5. Auftragskontrolle

- Unterauftragsverarbeiter siehe Anlage 1 des AVV; Auswahl mit EU-Standort
  bevorzugt (`deploy/README.md` Abschnitt 6 und 7).
- **offen**: AVV mit allen Unterauftragsverarbeitern abschließen und ablegen.
