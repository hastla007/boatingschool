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
| 14 | **Datensicherungen** (DB-Dump und Uploads) | `storage/app/backups`, optional `BACKUP_DISK` | **14 Tage** | `BACKUP_KEEP_DAYS` (lokal und extern) | 14 Tage; gelöschte Daten verschwinden spätestens nach 14 Tagen auch aus den Sicherungen |
| 15 | Fehlerberichte (optional) | Sentry | beim Anbieter, [90] Tage | Einstellung im Sentry-Projekt; Angabe in der Datenschutzerklärung über `PRIVACY_ERROR_TRACKING_RETENTION_DAYS` | 30–90 Tage |
| 16 | Praxisanmeldung, Termine | `praxis_form_submission`, `praxis_exam_appointment` | mit der Mitgliedschaft | Kontolöschung | **offen**: eigene Frist nach der Prüfung, z. B. [12 Monate] |
| 17 | Willkommensbox-Bestellungen (Lieferadresse) | `willkommensbox_order` | mit der Mitgliedschaft | Kontolöschung | **offen**: Lieferadresse nach Versand + [3 Monate] entfernen |
| 18 | Gerätewiedererkennung (HMAC) | `known_login_device` | mit dem Konto | Fremdschlüssel | [12 Monate ohne Nutzung] – **offen** |
| 19 | Shopkonten | `commerce_shop_account` | anonymisiert bei Kontolöschung; offene Registrierungen in Prüfung nach 180 Tagen mit dem Konto gelöscht | `AccountErasureService`, Nr. 3 | wie umgesetzt |
| 20 | Shop-Ereignisse (Outbox) | `commerce_event` | **keine Frist** | – | **offen**: nach Zustellung + [90 Tage] löschen |
| 21 | Shop-Bestellungen und Rechnungen | WooCommerce | im Shop | – | **offen**: [6/10 Jahre] nach § 147 AO / § 257 HGB, im Shop umsetzen |
| 22 | Daten einer Schule bei Vertragsende | alle Tabellen der Schule | sofort nach Export | `TenantOffboardingService` | wie umgesetzt; Sicherungen nach 14 Tagen |

## Datenschutzerklärung

Die Liste „Speicherdauer“ in der Datenschutzerklärung der Schulen wird aus
derselben Konfiguration erzeugt (`App\Support\PrivacyNotices::retention()`):
Aktivierte Fristen (Nr. 2, 3, 4, 11, 12) erscheinen dort automatisch mit dem
eingestellten Wert.

## Vor dem Aktivieren

1. Fristen Nr. 2, 11 und 12 mit Anwalt/DSB und den Schulen festlegen
   (Nachweispflichten zu Ausbildung und Prüfungsanmeldung?).
2. In der `.env` setzen, mit `--dry-run` prüfen
   (`php artisan app:prune-inactive-learners --dry-run`,
   `php artisan app:prune-personal-data --dry-run`).
3. Schulen informieren (Vorwarnungen gehen im Namen der Schule an deren Lernende).
