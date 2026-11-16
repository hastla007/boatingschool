> **ENTWURF – vor Verwendung anwaltlich prüfen lassen; keine Rechtsberatung**

# Löschkonzept

Grundsatz: Personenbezogene Daten werden gelöscht, sobald ihr Zweck entfällt
und keine Aufbewahrungspflicht besteht (Art. 5 Abs. 1 lit. e, Art. 17 DSGVO).
Für die Lerndaten entscheidet die Bootsschule als Verantwortliche; die Fristen
gelten technisch **plattformweit**.

Mechanismen:

- **`app:prune-personal-data`** – täglich (Scheduler, `routes/console.php`), der
  Teil Mobile-API zusätzlich stündlich (`--mobile-api-only`); `--dry-run` zeigt
  nur Zahlen.
- **`app:prune-inactive-learners`** – täglich 05:10 Uhr, nur wenn
  `DATA_RETENTION_INACTIVE_LEARNER_MONTHS` gesetzt ist; `--dry-run`.
- **`app:backup`** – löscht Sicherungen nach `BACKUP_KEEP_DAYS`.
- **Kontolöschung** (`AccountErasureService`): Selbstlöschung im Profil, Löschen
  eines Teilnehmers durch die Schule (nur Daten dieser Schule; das Konto
  verschwindet mit der letzten Mitgliedschaft), Löschung durch den Superadmin
  (alle Schulen). Protokolleinträge werden dabei pseudonymisiert, Shopkonten
  anonymisiert.
- **Kontolöschung aus der App** (`AppAccountDeletion` → `leaveTenant`): Antrag
  in der App, Bestätigung per Mail-Link (7 Tage gültig); danach wie die
  Selbstlöschung im Profil (nur diese Schule, das Konto mit der letzten
  Mitgliedschaft).
- **Vertragsende einer Schule** (`TenantOffboardingService`): Export, dann
  Löschung aller Daten der Schule per Kaskade.

## Fristen je Datenkategorie

| Nr. | Datenkategorie | Speicherort | Frist umgesetzt | Mechanismus / Konfiguration | Empfehlung |
|---|---|---|---|---|---|
| 1 | Konto, Stammdaten, Lernstand, Zugänge | `app_user`, `tenant_user`, `entitlement`, `progress` … | bis zur Löschung des Kontos bzw. der Mitgliedschaft | Kontolöschung (s. o.) | zusätzlich Inaktivitätsfrist (Nr. 2) aktivieren |
| 2 | Konten inaktiver Lernender | wie 1 | **aus** (Standard `null`) | `app:prune-inactive-learners`, `data_retention.inactive_learners.months` (`DATA_RETENTION_INACTIVE_LEARNER_MONTHS`); Vorwarnung per Mail 30 Tage vorher (`app_user.inactivity_warned_at`), maßgeblich sind letzte Anmeldung (`app_user.last_login_at`, Web und App) und letzte Lernaktivität; nie bei aktivem Kurszugang, nie für Team und Superadmins | **24 Monate** [mit Anwalt/Schulen abstimmen; Nachweispflichten der Schulen beachten] |
| 3 | Nie angenommene Einladungs-Konten | `app_user` (Status „eingeladen“) | 90 Tage | `data_retention.unclaimed_accounts` (`DATA_RETENTION_UNCLAIMED_ACCOUNTS_ENABLED`/`_DAYS`), nicht bei Zugängen, Käufen, Bestellungen, Praxisanmeldung oder kürzlich erneuter Einladung | 90 Tage |
| 4 | Sitzungen (Web) | `sessions` | 30 Tage nach letzter Aktivität | `data_retention.sessions` (`DATA_RETENTION_SESSIONS_ENABLED`/`_DAYS`) | 30 Tage |
| 5 | App-Sitzungen | `api_device_session` | 30 Tage nach Abmeldung/Ablauf | `app:prune-personal-data` (fest im Code) | 30 Tage |
| 6 | Datenexporte aus der App (inkl. Datei) | `api_data_export`, `storage/app/private/api-exports` | 60 Minuten | `mobile_api.export_ttl_minutes` (`MOBILE_API_EXPORT_TTL_MINUTES`), stündlicher Lauf | 60 Minuten |
| 7 | Idempotenz-Schlüssel der App (enthalten Antworten) | `api_idempotency_key` | 24 Stunden | `mobile_api.idempotency_hours` | 24 Stunden |
| 8 | Passwort-Reset-Tokens | `password_reset_tokens` | 60 Minuten | `auth.passwords.users.expire` | 60 Minuten |
| 9 | Einladungs-Links | `account_setup_tokens` | gültig 7 Tage, gespeichert bis 90 Tage (Nachweis „kürzlich eingeladen“) | `auth.passwords.invites.expire`, Nr. 3 | wie umgesetzt |
| 10 | Cache (u. a. Sperrzähler mit E-Mail/IP) | `cache` | nach Ablauf, täglich | `app:prune-personal-data`, `OpsAlert::pruneExpiredCache` | wie umgesetzt |
| 11 | Einzelne Antworten (Lern-Versuche) | `attempt` | **aus** | `data_retention.attempt` (`DATA_RETENTION_ATTEMPT_ENABLED`, `_MONTHS`, Vorschlag 36) | [36 Monate] – zusammengefasster Lernstand (`progress`) bleibt |
| 12 | Änderungsprotokoll | `audit_log` | **aus**; bei Kontolöschung pseudonymisiert | `data_retention.audit_log` (`DATA_RETENTION_AUDIT_LOG_ENABLED`, `_MONTHS`, Vorschlag 24) | [24 Monate] |
| 13 | Server-Protokolle (IP-Adressen) | `storage/logs/laravel-*.log` | 14 Tage | `LOG_DAILY_DAYS` | 14 Tage |
| 14 | **Datensicherungen** (DB-Dump und Uploads) | `storage/app/backups`, optional `BACKUP_DISK` (externe Kopien clientseitig verschlüsselt, `BACKUP_ENCRYPTION_PUBLIC_KEY`) | **14 Tage** | `BACKUP_KEEP_DAYS` (lokal; extern nur mit `BACKUP_PRUNE_REMOTE=true`, sonst per Lifecycle-Regel des Buckets); `app:backup:verify` löscht seine temporäre Datenbank sofort wieder | 14 Tage; gelöschte Daten verschwinden spätestens nach 14 Tagen auch aus den Sicherungen – bei `BACKUP_PRUNE_REMOTE=false` nur, wenn die Lifecycle-Regel auf 14 Tage steht |
| 15 | Fehlerberichte (optional) | Sentry | beim Anbieter, [90] Tage | Einstellung im Sentry-Projekt; Angabe in der Datenschutzerklärung über `PRIVACY_ERROR_TRACKING_RETENTION_DAYS` | 30–90 Tage |
| 16 | Praxisanmeldung, Termine | `praxis_form_submission`, `praxis_exam_appointment` | mit der Mitgliedschaft; Frist **aus** (Standard `null`) – **Frist durch Betreiber festlegen** | `data_retention.praxis_submissions.months` (`DATA_RETENTION_PRAXIS_SUBMISSION_MONTHS`): N Monate nach dem letzten Bezug (spätester Zeitpunkt aus letzter Änderung, fixiertem Prüfungstermin, Terminen und Rückmeldungen – ein anstehender Termin schützt immer, ebenso ein aktiver Zugang der Person zum Kurs) werden die Formularangaben (`data`) und die Freitext-Rückmeldungen (`response_comment`) geleert, ebenso deren Kopien im Änderungsprotokoll; Anmeldung, Kurs, Termine und Zeitpunkte bleiben (Zählungen, „bereits angemeldet“), Kennzeichen `_angaben_entfernt_am` in `data` | [12 Monate] – Nachweispflichten zur Prüfungsanmeldung mit den Schulen klären |
| 17 | Willkommensbox-Bestellungen (Lieferadresse) | `willkommensbox_order` | mit der Mitgliedschaft; Frist **aus** (Standard `null`) – **Frist durch Betreiber festlegen** | `data_retention.willkommensbox_addresses.days` (`DATA_RETENTION_WILLKOMMENSBOX_ADDRESS_DAYS`): nur versendete Bestellungen, N Tage nach `shipped_at` werden Straße, PLZ, Ort, Land, Anmerkung und Sendungsnummer geleert (auch im Änderungsprotokoll); die Bestellung selbst bleibt (Versandzahlen, keine zweite Bestellung). Nie vor dem Versand | [90 Tage] nach Versand (Reklamationen/Rücksendungen) |
| 18 | Gerätewiedererkennung (HMAC) | `known_login_device` | mit dem Konto; Frist **aus** (Standard `null`) – **Frist durch Betreiber festlegen** | `data_retention.known_login_devices.months` (`DATA_RETENTION_KNOWN_LOGIN_DEVICE_MONTHS`): Geräte mit `last_seen_at` älter als N Monate werden gelöscht; das zuletzt gesehene Gerät je Konto bleibt (sonst gälte die nächste Anmeldung – auch die eines Fremden – als „erstes Gerät“ ohne Hinweis-Mail) | [12 Monate ohne Nutzung] |
| 19 | Shopkonten | `commerce_shop_account` | anonymisiert bei Kontolöschung; offene Registrierungen in Prüfung nach 180 Tagen mit dem Konto gelöscht | `AccountErasureService`, Nr. 3 | wie umgesetzt |
| 20 | Shop-Ereignisse (Outbox) | `commerce_event` | Frist **aus** (Standard `null`) – **Frist durch Betreiber festlegen**; bei Kontolöschung werden Namen/E-Mail aus den Ereignissen entfernt | `data_retention.commerce_events.days` (`DATA_RETENTION_COMMERCE_EVENT_DAYS`): nur erledigte Ereignisse (`status` „done“: zugestellte Ausgangs-Ereignisse, protokollierte Shop-Aufrufe), gemessen ab `processed_at`. Nie wartende, gerade gesendete oder fehlgeschlagene Ausgangs-Ereignisse („pending“, „sending“, „failed“) und nie Storno-Ereignisse (`batch.voided`, Nachweis gegen doppelte Storno-Aufrufe; ohne Personendaten) | [90 Tage] nach Zustellung |
| 21 | Shop-Bestellungen und Rechnungen | WooCommerce | im Shop | – | **offen**: [6/10 Jahre] nach § 147 AO / § 257 HGB, im Shop umsetzen |
| 22 | Daten einer Schule bei Vertragsende | alle Tabellen der Schule | sofort nach Export | `TenantOffboardingService` | wie umgesetzt; Sicherungen nach 14 Tagen |
| 23 | Löschanträge aus der App | `api_account_deletion_request` | nach Ablauf (Bestätigung nicht mehr möglich), stündlich | `app:prune-personal-data --mobile-api-only` (fest im Code) | wie umgesetzt (technisch tote Zeilen) |
| 24 | Einmal-Codes der App-Webansicht | `api_mobile_web_session` | 1 Tag nach Ablauf (gültig 60 Sekunden), stündlich | wie Nr. 23 (fest im Code) | wie umgesetzt (technisch tote Zeilen) |
| 25 | Lerngruppen-Einladungen | `learning_group_member` (Status „eingeladen“) | nach Ablauf der Einladung werden E-Mail und Code entfernt (Status „abgelaufen“) | wie Nr. 23 | wie umgesetzt; aktive Mitgliedschaften mit dem Konto |
| 26 | Shop-Verknüpfungscodes | `commerce_link_code` | 1 Tag nach Ablauf (gültig 15 Minuten), täglich | `app:prune-personal-data` (fest im Code); die Verknüpfung selbst steht am Shopkonto und im Protokoll | wie umgesetzt (technisch tote Zeilen) |
| 27 | App-Einstellungen | `api_user_setting` | mit dem ganzen Konto (nicht beim Verlassen nur einer Schule) | Fremdschlüssel | keine eigene Frist nötig (Teil des Kontos) |
| 28 | Lerngruppen-Mitgliedschaften | `learning_group_member` | mit der Mitgliedschaft der Person in der Schule (`AccountErasureService::leaveLearningGroups`); eine verwaltete Gruppe bleibt ohne Verwalter bestehen | Kontolöschung | **offen**: Frist für beendete Gruppen |
| 29 | Fehlgeschlagene Queue-Jobs (können Mail-Inhalte enthalten) | `failed_jobs` | **offen** – keine Frist | – | Vorschlag: täglich `queue:prune-failed --hours=[168]` |
| 30 | Zugriffsprotokolle des Webservers, Scheduler-Log | Webserver-Logs, `storage/logs/scheduler.log` | 14 Tage | logrotate bzw. Caddy `roll_keep_for 336h` (`deploy/`) | 14 Tage |
| 31 | Nie angenommene Einladungen einer Schule (Name, E-Mail, ggf. reservierter Code) | `tenant_invitation` (ohne `accepted_at`) | gültig 7 Tage, gelöscht nach **90 Tagen** (also 83 Tage nach Ablauf); angenommene Einladungen bleiben als Nachweis der Annahme | `data_retention.tenant_invitations.days` (`DATA_RETENTION_TENANT_INVITATION_DAYS`, `0` = aus), `app:prune-personal-data`; nie kürzer als Nr. 3, solange diese aktiv ist (Nachweis „kürzlich eingeladen“) | 90 Tage |

## Datenschutzerklärung

Die Liste „Speicherdauer“ in der Datenschutzerklärung der Schulen wird aus
derselben Konfiguration erzeugt (`App\Support\PrivacyNotices::retention()`):
Aktivierte Fristen (Nr. 2, 3, 4, 11, 12, 16, 17, 31) erscheinen dort automatisch mit
dem eingestellten Wert. Nr. 18 (nur Team und Superadmins) und Nr. 20 (Shop der
Schule) betreffen keine Lernenden. Zusätzlich nennt die Datenschutzerklärung
fest die Fristen für Lerngruppen-Einladungen, Löschanträge aus der App und die
Einmal-Codes der App-Webansicht (Nr. 23–25).

## Vor dem Aktivieren

1. Fristen Nr. 2, 11, 12, 16, 17, 18 und 20 mit Anwalt/DSB und den Schulen
   festlegen (Nachweispflichten zu Ausbildung und Prüfungsanmeldung?). Für
   Nr. 16, 17, 18 und 20 ist der Mechanismus vorhanden, aber standardmäßig aus:
   **Frist durch Betreiber festlegen** (Variablen in `.env.example`).
2. In der `.env` setzen, mit `--dry-run` prüfen
   (`php artisan app:prune-inactive-learners --dry-run`,
   `php artisan app:prune-personal-data --dry-run`).
3. Schulen informieren (Vorwarnungen gehen im Namen der Schule an deren Lernende).
