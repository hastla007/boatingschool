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
| Daten | Sperrzähler (E-Mail/IP, im Cache, kurzlebig), `known_login_device` (HMAC, Zeitpunkte), Fehlerberichte ohne IP, Sicherungen |
| Empfänger | Hosting, [Sentry, optional], [Sicherungsspeicher] |
| Löschung | Cache nach Ablauf (täglich), Geräte mit dem Konto, Fehlerberichte [90] Tage beim Anbieter, Sicherungen 14 Tage |

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
| Daten | `app_user`, `tenant_user`, `tenant_invitation`, `account_setup_tokens`, `sessions`, `api_device_session` |
| Unterauftragsverarbeiter | Hosting, Mail |
| Drittland | nein |
| Fristen | siehe Löschkonzept Nr. 1–5, 13 |

### B2. Kurszugänge und Codes

| Feld | Angabe |
|---|---|
| Verarbeitung | Freischaltungen, Einlösen von Codes, Ablauf und Erinnerung, Produktkäufe |
| Daten | `entitlement`, `coupon`, `product_purchase` |
| Fristen | mit dem Konto bzw. der Mitgliedschaft |

### B3. Lernen und Prüfungssimulation

| Feld | Angabe |
|---|---|
| Verarbeitung | Fragentrainer, Smarttrainer, Favoriten, Prüfungssimulation, Navigations-, Praxis- und Funktrainer, Videokurs |
| Daten | `attempt`, `progress`, `favorite`, `favorite_collection*`, `exam_session*`, `navigation_progress`, `praxis_progress`, `radio_training_progress`, `video_progress` |
| Besonderheit | Funkübungen: freiwillige Spracherkennung läuft im Browser der Lernenden (Web Speech API), bei der Plattform kommt nur Text an |
| Unterauftragsverarbeiter | Hosting, Videohoster (Abruf der Videos) |
| Fristen | mit dem Konto; einzelne Antworten optional nach `DATA_RETENTION_ATTEMPT_MONTHS` |

### B4. Praxisprüfung und Willkommensbox

| Feld | Angabe |
|---|---|
| Verarbeitung | Anmeldeformular, Terminvorschläge und Rückmeldungen, Prüfungsausschuss, Bestellung und Versand der Willkommensbox |
| Daten | `praxis_form_submission`, `praxis_exam_appointment`, `course_exam_registration`, `willkommensbox_order`; Anschrift und Geburtsdatum aus `tenant_user` |
| Empfänger | die Bootsschule; ggf. Prüfungsausschuss durch die Schule selbst |
| Fristen | mit der Mitgliedschaft – **offen**: eigene Frist nach der Prüfung |

### B5. Kommunikation der Schule

| Feld | Angabe |
|---|---|
| Verarbeitung | Einladungen, Lernerinnerungen, Mitteilungen der Schule, Ablauf-Erinnerungen, Lösch-Vorwarnung bei Inaktivität |
| Daten | E-Mail-Adresse, Name, `tenant_announcement`, `tenant_user.reminded_at`, `app_user.inactivity_warned_at` |
| Unterauftragsverarbeiter | Mail-Anbieter |

### B6. Verwaltung durch die Schule und Protokoll

| Feld | Angabe |
|---|---|
| Verarbeitung | Teilnehmerverwaltung, Auswertungen der Schule, Änderungsprotokoll, Export und Löschung durch die Schule |
| Daten | `audit_log`, Lernstände (lesend) |
| Fristen | Protokoll bis zur Kontolöschung (dann pseudonymisiert) bzw. `DATA_RETENTION_AUDIT_LOG_MONTHS` |

### B7. Betrieb: Datensicherung, Fehleranalyse, Support-Ansicht

| Feld | Angabe |
|---|---|
| Verarbeitung | tägliche Sicherung (14 Tage), Fehlerberichte (optional), lesende Support-Ansicht durch Superadmins (60 Min., protokolliert) |
| Unterauftragsverarbeiter | Sicherungsspeicher, [Sentry] |
| Drittland | nur bei Sentry ohne EU-Region (USA, DPF/SCC) |
