> **ENTWURF – vor Verwendung anwaltlich prüfen lassen; keine Rechtsberatung**

# Verzeichnis von Verarbeitungstätigkeiten

Verantwortlicher bzw. Auftragsverarbeiter: **[Betreiber]**, [Anschrift],
[Vertretung], Datenschutzbeauftragte(r): [Name, Kontakt]

Teil A: eigene Verarbeitungen des Betreibers als **Verantwortlicher**
(Art. 30 Abs. 1 DSGVO). Teil B: Verarbeitungen **im Auftrag** der Bootsschulen
(Art. 30 Abs. 2 DSGVO). Fristen: [loeschkonzept.md](loeschkonzept.md),
Maßnahmen: [tom.md](tom.md).

---

## Teil A – Betreiber als Verantwortlicher

### A1. Code-Shop für Bootsschulen (B2B, WooCommerce)

| Feld | Angabe |
|---|---|
| Zweck | Verkauf von Zugangs-, Verlängerungs- und Produktcodes an Bootsschulen; Lieferung der Codes in den Codebestand der Schule; Rechnungsstellung |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. b DSGVO (Vertrag), lit. c (handels- und steuerrechtliche Aufbewahrung) |
| Betroffene | Inhaber und Mitarbeitende von Bootsschulen (Shopkunden) |
| Daten | Shop: Name, Firma, E-Mail, Rechnungsanschrift, USt-IdNr., Bestellungen, Zahlungsstatus. Plattform: `commerce_shop_account` (E-Mail, Kontakt, Firma, USt-IdNr., Verknüpfung zur Schule), `commerce_event` (Ereignisse an den Shop), `coupon_batch` (Bestellposition ↔ Codes) |
| Empfänger | [Shop-Hosting], [Zahlungsdienstleister], [Steuerberatung] |
| Drittland | [keins / prüfen Zahlungsdienstleister] |
| Löschung | Shop: nach Ablauf der Aufbewahrungspflichten ([6/10 Jahre], im Shop-System umzusetzen – **offen**). Plattform: Shopkonten werden bei Kontolöschung anonymisiert; ausgehende Ereignisse ohne Namen/E-Mail; keine automatische Frist für `commerce_event` – **offen** |
| TOM | HMAC-signierte Schnittstelle, Codes verlassen die Plattform nie, siehe `docs/commerce/README.md` |

### A2. Verwaltung der Bootsschulen (Vertragskunden) und Superadmin

| Feld | Angabe |
|---|---|
| Zweck | Anlage und Betreuung der Schulen, Inhaberkonten, Plattform-Mitteilungen, Vertragsende (Export und Löschung) |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. b DSGVO |
| Betroffene | Ansprechpersonen und Inhaber der Schulen, Superadmins |
| Daten | Name, E-Mail, Telefon, Firmendaten (Branding: Firmenname, Anschrift, Vertretung, Register, USt-IdNr.), Protokoll der Superadmin-Aktionen |
| Empfänger | Hosting, Mail-Anbieter |
| Löschung | Bei Vertragsende Export und Löschung der Schule; Firmendaten nach Vertragsende soweit keine Aufbewahrungspflicht [prüfen] |

### A3. Zentrale Plattformseite (ohne Schule)

| Feld | Angabe |
|---|---|
| Zweck | Startseite/Schulwahl, Impressum, Datenschutz, sicherer Betrieb |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse am sicheren Betrieb) |
| Betroffene | Besucher |
| Daten | IP-Adresse, Zeitpunkt, aufgerufene Adresse (Server-Protokolle), technisch notwendige Cookies |
| Empfänger | Hosting |
| Löschung | Server-Protokolle 14 Tage (`LOG_DAILY_DAYS`), Sitzungen 30 Tage |

### A4. Sicherheit des Betriebs (plattformweit)

| Feld | Angabe |
|---|---|
| Zweck | Missbrauchsschutz (Anmeldegrenzen), Hinweis bei neuem Gerät (Team-Konten), Fehleranalyse, Datensicherung |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. f DSGVO; für Lerndaten zugleich Auftragsverarbeitung (Teil B) |
| Betroffene | alle Nutzenden |
| Daten | Sperrzähler (E-Mail/IP, im Cache, kurzlebig), `known_login_device` (HMAC, Zeitpunkte), Fehlerberichte ohne IP, Sicherungen (externe Kopie clientseitig verschlüsselt, `BACKUP_ENCRYPTION_PUBLIC_KEY`), Zugriffsprotokolle des Webservers (IP, Zeitpunkt, Adresse ohne Query-String bzw. mit unkenntlich gemachten Token, Referer gekürzt, `deploy/nginx.conf`, `deploy/Caddyfile`) |
| Empfänger | Hosting, [Sentry, optional], [Sicherungsspeicher – erhält nur verschlüsselte Dateien, sofern `BACKUP_ENCRYPTION_PUBLIC_KEY` gesetzt]; Totmann-Dienst ([z. B. healthchecks.io], `OPS_HEARTBEAT_URL`/`OPS_BACKUP_HEARTBEAT_URL`) erhält nur Pings des Servers (Server-IP, Zeitpunkt), keine personenbezogenen Daten |
| Löschung | Cache nach Ablauf (täglich), Geräte mit dem Konto, Fehlerberichte [90] Tage beim Anbieter, Sicherungen 14 Tage, Zugriffsprotokolle 14 Tage (logrotate/Caddy `roll_keep_for 336h`) |

### A5. Kontakt/Support des Betreibers

| Feld | Angabe |
|---|---|
| Zweck | Bearbeitung von Anfragen an die Support-Adresse der Plattform |
| Rechtsgrundlage | Art. 6 Abs. 1 lit. b bzw. f DSGVO |
| Betroffene | Anfragende (Schulen, ggf. Lernende) |
| Daten | Kontaktdaten, Inhalt der Anfrage |
| Empfänger | [E-Mail-Postfach-Anbieter], ggf. WhatsApp, wenn die Schule den Support-Knopf aktiviert [prüfen: Verantwortlichkeit] |
| Löschung | [z. B. 2 Jahre nach Abschluss] – **offen** |

---

## Teil B – Betreiber als Auftragsverarbeiter (Art. 30 Abs. 2 DSGVO)

Auftraggeber (Verantwortliche): die jeweilige Bootsschule laut Liste der
Mandanten im Superadmin ([Export als Anlage]). Kategorien von Verarbeitungen:

### B1. Konto und Mitgliedschaft

| Feld | Angabe |
|---|---|
| Verarbeitung | Registrierung/Einladung, Anmeldung (Web, App), Profil, 2FA, letzte Anmeldung |
| Betroffene | Lernende (ab 14 Jahren), Team der Schule |
| Daten | `app_user`, `tenant_user`, `tenant_invitation`, `account_setup_tokens`, `sessions`, `api_device_session`; App-Anmeldung der Webansicht und Kontolöschung aus der App siehe B9 |
| Unterauftragsverarbeiter | Hosting, Mail |
| Drittland | nein |
| Fristen | siehe Löschkonzept Nr. 1–5, 13 |

### B2. Kurszugänge und Codes

| Feld | Angabe |
|---|---|
| Verarbeitung | Freischaltungen (ganzer Kurs oder einzelne Funktionen, `entitlement.capabilities`), Einlösen von Codes, Ablauf und Erinnerung, Produktkäufe; Zugangsregeln der Schule (Probezugang, zeitlich gestaffelte Freigabe „Drip“, Funktionszugang) werden aus dem Zugang und seinem Beginn berechnet, ohne eigene personenbezogene Speicherung (`course_access_rule` enthält nur Regeln je Kurs); Lerngruppen siehe B8 |
| Daten | `entitlement`, `coupon`, `product_purchase` |
| Fristen | mit dem Konto bzw. der Mitgliedschaft |

### B3. Lernen und Prüfungssimulation

| Feld | Angabe |
|---|---|
| Verarbeitung | Fragentrainer, Smarttrainer, Favoriten, Prüfungssimulation (inkl. markierter Fragen), Navigations- (inkl. Selbstbewertung je Aufgabe), Praxis- (Status je Aufgabe) und Funktrainer, Videokurs, Tipps, Kunden-FAQs und Kursdateien (Downloads nur mit Zugang bzw. Funktionszugang) |
| Daten | `attempt`, `progress`, `favorite`, `favorite_collection*`, `exam_session*` (`exam_session_question.flagged`), `navigation_progress` (`self_assessment`), `praxis_progress` (`status`), `radio_training_progress`, `video_progress`; Kunden-FAQs, Tipps und Kursdateien (`customer_faq*`, `course_file`) enthalten keine Daten der Lernenden |
| Besonderheit | Funkübungen: freiwillige Spracherkennung läuft im Browser der Lernenden (Web Speech API), bei der Plattform kommt nur Text an. Geschützte Dateien (Fragenbilder, Kartenausschnitte, Tipp-PDFs, Bilder der Videoschritte) nur über kurzlebige, signierte Adressen mit Konto- und Schulkennung (UUID) |
| Unterauftragsverarbeiter | Hosting, Videohoster (Abruf der Videos) |
| Fristen | mit dem Konto; einzelne Antworten optional nach `DATA_RETENTION_ATTEMPT_MONTHS` |

### B4. Praxisprüfung und Willkommensbox

| Feld | Angabe |
|---|---|
| Verarbeitung | Anmeldeformular, Terminvorschläge und Rückmeldungen, Prüfungsausschuss, Bestellung und Versand der Willkommensbox; Prüfungsanmeldung in der App nur lesend (`GET /api/v1/courses/{id}/exam-registration`, `ExamRegistrationController`): Modus der Schule (Anmeldung durch die Schule oder Selbstanmeldung), Anleitung, Gebühren und Links zu den Prüfungsstellen. Die App übermittelt dabei **keine** personenbezogenen Daten an Dritte; die Anmeldung selbst läuft nie über die App (Termintool der Schule im Web bzw. Selbstanmeldung der Lernenden direkt bei der Prüfungsstelle) |
| Daten | `praxis_form_submission`, `praxis_exam_appointment`, `course_exam_registration` (nur Modus je Schule und Kurs), `willkommensbox_order`; Anschrift und Geburtsdatum aus `tenant_user` |
| Empfänger | die Bootsschule; ggf. Prüfungsausschuss durch die Schule selbst (nicht durch die Plattform) |
| Fristen | mit der Mitgliedschaft – **offen**: eigene Frist nach der Prüfung |

### B5. Kommunikation der Schule

| Feld | Angabe |
|---|---|
| Verarbeitung | Einladungen, Lernerinnerungen, Mitteilungen der Schule, Ablauf-Erinnerungen, Lösch-Vorwarnung bei Inaktivität, Einladungen in Lerngruppen (B8), Bestätigung der Kontolöschung aus der App (B9) |
| Daten | E-Mail-Adresse, Name, `tenant_announcement`, `tenant_user.reminded_at`, `app_user.inactivity_warned_at`; Warteschlange `jobs`/`failed_jobs` (Sammel-Mails werden eingereiht und vom Queue-Worker verschickt, `deploy/supervisor-worker.conf`; Einladungen mit Codes bzw. Links verschlüsselt eingereiht, `ShouldBeEncrypted`) |
| Unterauftragsverarbeiter | Mail-Anbieter |
| Fristen | Jobs bis zum Versand; fehlgeschlagene Jobs (`failed_jobs`) ohne automatische Frist – **offen** (siehe Löschkonzept) |

### B6. Verwaltung durch die Schule und Protokoll

| Feld | Angabe |
|---|---|
| Verarbeitung | Teilnehmerverwaltung, Auswertungen der Schule, Änderungsprotokoll, Export und Löschung durch die Schule |
| Daten | `audit_log`, Lernstände (lesend) |
| Fristen | Protokoll bis zur Kontolöschung (dann pseudonymisiert) bzw. `DATA_RETENTION_AUDIT_LOG_MONTHS` |

### B7. Betrieb: Datensicherung, Fehleranalyse, Support-Ansicht

| Feld | Angabe |
|---|---|
| Verarbeitung | tägliche Sicherung (14 Tage; externe Kopie vor dem Hochladen mit libsodium verschlüsselt, wenn `BACKUP_ENCRYPTION_PUBLIC_KEY` gesetzt; geheimer Schlüssel nicht auf dem Server), monatliche Probe-Wiederherstellung (`app:backup:verify`, temporäre Datenbank), Fehlerberichte (optional), lesende Support-Ansicht durch Superadmins (60 Min., protokolliert) |
| Unterauftragsverarbeiter | Sicherungsspeicher, [Sentry] |
| Drittland | nur bei Sentry ohne EU-Region (USA, DPF/SCC) |

### B8. Lerngruppen (Gruppenlizenzen)

| Feld | Angabe |
|---|---|
| Verarbeitung | Inhaber/Admin der Schule legen im Admin-Bereich eine Gruppe mit fester Platzzahl, Kursen bzw. Funktionen, Ende und Verwalter an (oder der Betreiber per `app:learning-group-create`); der Verwalter – ein aktiver Teilnehmer der Schule – vergibt die Plätze in der App per Beitrittscode oder persönlicher Einladung an eine E-Mail-Adresse, entfernt Mitglieder und erneuert den Code. Der Beitritt legt je Kurs einen Gruppenzugang an (`entitlement`, `source_type = group`) und verbraucht je Kurs einen freien Kurscode der Schule (der Code wird auf die Person eingelöst), außer bei eigenem Vollzugang |
| Rechtsgrundlage (für die Schule) | Art. 6 Abs. 1 lit. b DSGVO (Kurszugang über die Gruppe); Einladungen an Personen, die noch nicht Mitglied sind: Art. 6 Abs. 1 lit. f DSGVO [prüfen; Informationspflicht nach Art. 14 DSGVO für Eingeladene – Hinweis in der Einladungsmail?] |
| Betroffene | Mitglieder und Verwalter (Lernende der Schule), eingeladene Personen (auch ohne Konto) |
| Daten | `learning_group` (Name, Plätze, Kurse/Funktionen, Ende, Verwalter, Beitrittscode), `learning_group_member` (Konto bzw. Einladungsadresse, Status eingeladen/aktiv/entfernt/abgelaufen, Einladungscode, Zeitpunkte von Einladung und Beitritt), `entitlement`, eingelöste `coupon`, `audit_log` |
| Wer sieht was | **Verwalter (App)**: Name der Gruppe, Plätze, Kurse, Beitrittscode, je Mitglied Name, Einladungsadresse, Status, Einladungs- und Beitrittsdatum – **keine** Lerndaten; erfährt nicht, ob es zu einer eingeladenen Adresse ein Konto gibt. **Inhaber/Admin der Schule (Admin-Bereich)**: alle Gruppen der Schule mit denselben Angaben (Mitglieder mit Konto-E-Mail) und dem Verbrauch der Kurscodes; Lerndaten wie bei allen Teilnehmern. **Beitretende**: vor dem Beitritt Name der Gruppe, Name des Verwalters, freie Plätze, Kurse. Andere Mitglieder sehen einander nicht |
| Empfänger | Mail-Anbieter (Einladungsmail im Namen der Schule mit Name des Verwalters und persönlichem Code) |
| Drittland | nein |
| Fristen | offene Einladungen 30 Tage, danach Adresse und Code entfernt (`app:prune-personal-data`, stündlich); Entfernen und Gruppenende löschen Adresse und Code sofort; Mitgliedschaften mit der Mitgliedschaft der Person bei der Schule (`AccountErasureService::leaveLearningGroups`; verwaltete Gruppen bleiben ohne Verwalter bestehen); beendete Gruppen ohne eigene Frist – **offen** |
| TOM | RLS je Schule auf beiden Tabellen, zusammengesetzte Fremdschlüssel (Mitglied und Verwalter nur aus derselben Schule), Sperre der Gruppenzeile bei jeder Platzänderung, Einladungsgrenzen (je Gruppe und Tag 20 bzw. doppelte Platzzahl, je Verwalter 20 Anfragen je Stunde), Einladungsmails verschlüsselt in der Warteschlange |

### B9. Lern-App: Einstellungen, Anmeldung der Webansicht, Kontolöschung

| Feld | Angabe |
|---|---|
| Verarbeitung | (a) **App-Einstellungen** je Konto geräteübergreifend speichern (`GET/PUT /api/v1/me/settings`); (b) **Einmal-Anmeldung der Webansicht** aus der App (`POST /api/v1/auth/web-session`, `WebSessionController`, `ConsumeMobileWebSession`): Code 60 Sekunden, einmalig, an Konto, Gerätesitzung und Schule gebunden, zusätzlich Verifier im Header, nur Ziele im Lernbereich, nicht für Konten mit Verwaltungsrechten, abschaltbar (`MOBILE_API_WEB_SESSION_ENABLED`); (c) **Kontolöschung aus der App** (`POST /api/v1/me/deletion-request`): Mail mit Bestätigungslink (7 Tage gültig), Bestätigung in der App oder auf der Web-Bestätigungsseite (`MOBILE_API_DELETION_CONFIRMATION_PATH`, `AppAccountDeletionController`); danach dieselbe Löschung wie „Konto löschen“ im Web (`AccountErasureService::leaveTenant`), nur für Lernende |
| Rechtsgrundlage (für die Schule) | (a), (b) Art. 6 Abs. 1 lit. b DSGVO; (c) Art. 6 Abs. 1 lit. c i. V. m. Art. 17 DSGVO |
| Betroffene | Lernende, die die App nutzen |
| Daten | (a) `api_user_setting` (JSON bis 8 KB, Version; **schulübergreifend** je Konto, ohne `tenant_id`/RLS, Zugriff nur über die eigene Konto-ID); (b) `api_mobile_web_session` (Konto, Gerätesitzung, Schule, Code- und Verifier-Hash, Zielpfad, Ablauf, Einlösezeitpunkt); (c) `api_account_deletion_request` (Konto, Schule, Hash des Bestätigungsschlüssels, Zeitpunkte), Protokolleintrag `profile.delete` ohne Personenbezug |
| Empfänger | Mail-Anbieter (Bestätigungsmail) |
| Drittland | nein |
| Fristen | (a) bis zur Löschung des Kontos, Teil des Datenexports; (b) einen Tag nach Ablauf (`app:prune-personal-data`, stündlich); (c) bis zur Bestätigung bzw. bis zum Ablauf nach 7 Tagen (stündlich gelöscht) |
| Besonderheit | (a) Weil die Einstellungen für alle Schulen eines Kontos gelten, ist die Zuordnung zu einer verantwortlichen Schule nicht eindeutig [prüfen: gemeinsame Verantwortlichkeit bzw. Betreiber als Verantwortlicher?]; sie werden erst mit dem ganzen Konto gelöscht, nicht beim Austritt aus nur einer Schule |
