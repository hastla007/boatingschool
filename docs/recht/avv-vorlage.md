> **ENTWURF – vor Verwendung anwaltlich prüfen lassen; keine Rechtsberatung**

# Vertrag über die Auftragsverarbeitung (Art. 28 DSGVO)

zwischen

**[Name der Bootsschule]**, [Anschrift], vertreten durch [Vertretungsberechtigte Person]
– nachfolgend „Verantwortlicher“ oder „Bootsschule“ –

und

**[Betreiber]**, [Anschrift], vertreten durch [Geschäftsführung]
– nachfolgend „Auftragsverarbeiter“ oder „Betreiber“ –

## § 1 Gegenstand und Dauer

(1) Der Auftragsverarbeiter stellt dem Verantwortlichen die Lernplattform
„[Plattformname]“ (Web und Lern-App) zur Ausbildung von Bootsführerschein-,
Funk- und verwandten Kursen bereit (Hauptvertrag: [Bezeichnung/Datum des
Nutzungsvertrags]). Dabei verarbeitet er personenbezogene Daten im Auftrag des
Verantwortlichen.

(2) Die Laufzeit dieses Vertrags entspricht der Laufzeit des Hauptvertrags.
Pflichten zur Löschung und Rückgabe (§ 10) gelten darüber hinaus.

## § 2 Art, Zweck und Umfang der Verarbeitung

(1) **Zweck**: Bereitstellung der Online-Kurse (Fragentrainer, Prüfungssimulation,
Videokurs, Navigations-, Praxis- und Funktrainer), Verwaltung der Teilnehmenden
und Freischaltungen, Organisation der Praxisprüfung (Anmeldeformular,
Terminvorschläge), Bestellung und Versand der Willkommensbox, Versand von
E-Mails der Bootsschule (Einladungen, Erinnerungen, Mitteilungen), Betrieb der
Lern-App, Datensicherung, Fehleranalyse und Support.

(2) **Art der Verarbeitung**: Erheben, Speichern, Organisieren, Auslesen,
Abfragen, Übermitteln (an die Bootsschule und die Unterauftragsverarbeiter nach
Anlage 1), Einschränken, Löschen.

(3) **Kategorien betroffener Personen**:

- Lernende der Bootsschule, **auch Minderjährige ab 14 Jahren**,
- Mitarbeitende der Bootsschule (Inhaber, Admins, Ausbilder),
- eingeladene, noch nicht registrierte Personen.

(4) **Kategorien personenbezogener Daten**:

| Kategorie | Beispiele (Tabellen) |
|---|---|
| Konto- und Stammdaten | Name, E-Mail, Telefon, Passwort-Hash, Sprache, 2FA-Status, letzte Anmeldung (`app_user`); je Schule Anschrift und Geburtsdatum für die Prüfungsanmeldung (`tenant_user`) |
| Zugänge und Käufe | Freischaltungen, eingelöste Codes, Produktkäufe (`entitlement`, `coupon`, `product_purchase`) |
| Lerndaten | Antworten, Lernstand, Prüfungssimulationen, Navigations-, Praxis- und Funkübungen, Videofortschritt, Favoriten (`attempt`, `progress`, `exam_session`, `navigation_progress`, `praxis_progress`, `radio_training_progress`, `video_progress`, `favorite*`) |
| Praxisprüfung | Formularangaben, Termine und Rückmeldungen (`praxis_form_submission`, `praxis_exam_appointment`, `course_exam_registration`) |
| Willkommensbox | Lieferadresse, Versandstatus (`willkommensbox_order`) |
| Lern-App | Gerätename, Plattform, App-Version, Zeitpunkte der Nutzung (`api_device_session`) |
| Technische Daten | Sitzungen, IP-Adressen in Server-Protokollen, Änderungsprotokoll (`sessions`, Logdateien, `audit_log`) |

Besondere Kategorien nach Art. 9 DSGVO werden nicht planmäßig verarbeitet.
Freitextfelder (z. B. Kommentare zu Prüfungsterminen) könnten sie enthalten; die
Bootsschule weist ihre Lernenden darauf hin, dort keine Gesundheitsdaten
einzutragen. [prüfen]

## § 3 Weisungen

(1) Der Auftragsverarbeiter verarbeitet die Daten nur auf dokumentierte Weisung
des Verantwortlichen; die Weisungen sind abschließend in diesem Vertrag und in
den Einstellungen der Plattform (z. B. Löschen von Teilnehmern, Freischaltungen,
2FA-Pflicht fürs Team) festgelegt. Weitere Weisungen erfolgen in Textform an
[Kontaktadresse Betreiber].

(2) Hält der Auftragsverarbeiter eine Weisung für rechtswidrig, weist er den
Verantwortlichen unverzüglich darauf hin (Art. 28 Abs. 3 Satz 3 DSGVO).

## § 4 Pflichten des Auftragsverarbeiters

(1) Vertraulichkeit: Alle Personen mit Zugriff sind auf Vertraulichkeit
verpflichtet. Zugriff auf Daten einer Schule haben beim Betreiber nur
Superadmins; die Support-Ansicht ist nur lesend, endet nach 60 Minuten und wird
protokolliert.

(2) Technische und organisatorische Maßnahmen nach Art. 32 DSGVO: siehe
**Anlage 2** ([tom.md](tom.md)). Der Auftragsverarbeiter darf sie
weiterentwickeln, sofern das Schutzniveau nicht unterschritten wird.

(3) Unterstützung bei Betroffenenrechten (Art. 12–23 DSGVO): Die Plattform
stellt dafür bereit: Datenexport je Person (Profil bzw. Teilnehmerverwaltung),
Löschung von Teilnehmern durch die Bootsschule, Selbstlöschung im Profil,
Berichtigung im Profil bzw. durch die Schule. Darüber hinausgehende
Unterstützung: [Vergütungsregel].

(4) Unterstützung bei Art. 32–36 DSGVO (Sicherheit, Meldungen, DSFA) im Rahmen
der verfügbaren Informationen.

(5) Meldung von Datenschutzverletzungen an den Verantwortlichen unverzüglich,
spätestens innerhalb von [24/48] Stunden nach Kenntnis, mit den Angaben nach
Art. 33 Abs. 3 DSGVO, soweit verfügbar.

(6) Datenschutzbeauftragter des Auftragsverarbeiters: [Name, Kontakt] bzw.
[nicht benannt, weil …].

## § 5 Unterauftragsverarbeiter

(1) Der Verantwortliche genehmigt die in **Anlage 1** genannten
Unterauftragsverarbeiter.

(2) Der Auftragsverarbeiter informiert über beabsichtigte Änderungen mindestens
[30] Tage vorher in Textform; der Verantwortliche kann aus wichtigem
datenschutzrechtlichen Grund widersprechen. [Folgen des Widerspruchs regeln]

(3) Der Auftragsverarbeiter erlegt den Unterauftragsverarbeitern dieselben
Datenschutzpflichten auf (Art. 28 Abs. 4 DSGVO).

## § 6 Ort der Verarbeitung, Drittländer

Die Verarbeitung findet in der EU/im EWR statt. Eine Übermittlung in ein
Drittland erfolgt nur, wenn die Voraussetzungen der Art. 44 ff. DSGVO erfüllt
sind (siehe Anlage 1, Spalte „Drittland/Garantie“).

## § 7 Kontrollrechte

Der Verantwortliche kann sich von der Einhaltung der Pflichten überzeugen,
vorrangig durch Vorlage aktueller Nachweise (Anlage 2, Zertifikate des
Hosting-Anbieters), nach Ankündigung von [14] Tagen auch durch Inspektionen
während der Geschäftszeiten. [Kostenregel]

## § 8 Pflichten des Verantwortlichen

(1) Der Verantwortliche ist für die Rechtmäßigkeit der Verarbeitung, die
Information der Betroffenen (Datenschutzerklärung je Schule, Vorlage in der
Plattform) und die Wahrung der Betroffenenrechte verantwortlich.

(2) Er pflegt seine Stammdaten (Impressum, Support-Adresse) in der Plattform und
legt fest, ob und wann Teilnehmende gelöscht werden.

(3) Für minderjährige Lernende stellt er sicher, dass die Teilnahme rechtlich
wirksam vereinbart ist. [prüfen]

## § 9 Löschfristen

Die automatisch ausgeführten Fristen und ihre Einstellungen sind in
[loeschkonzept.md](loeschkonzept.md) beschrieben. Abweichende Fristen
(z. B. für Lern-Antworten oder inaktive Konten) legt der Verantwortliche in
Abstimmung mit dem Betreiber fest; sie gelten plattformweit. [prüfen, ob
schulindividuelle Fristen nötig sind]

## § 10 Beendigung, Rückgabe und Löschung

(1) Bei Vertragsende stellt der Auftragsverarbeiter auf Wunsch einen
vollständigen Export der Daten der Bootsschule (JSON je Mitglied) bereit und
löscht danach alle Daten der Schule (Mitgliedschaften, Freischaltungen,
Lernstände, Anmeldungen, Bestellungen, Protokoll, Logo). Konten, die keiner
anderen Schule mehr angehören, werden gelöscht (`TenantOffboardingService`).

(2) Kopien in Datensicherungen werden mit Ablauf der Aufbewahrung (14 Tage)
gelöscht. Gesetzliche Aufbewahrungspflichten des Auftragsverarbeiters bleiben
unberührt.

## § 11 Haftung

Es gilt Art. 82 DSGVO. [Weitere Regelungen / Verweis auf Hauptvertrag – anwaltlich festlegen]

## § 12 Schlussbestimmungen

Änderungen bedürfen der Textform. Bei Widersprüchen geht dieser Vertrag in
Datenschutzfragen dem Hauptvertrag vor. Gerichtsstand: [Ort].

[Ort, Datum] – [Unterschrift Bootsschule] – [Unterschrift Betreiber]

---

## Anlage 1: Unterauftragsverarbeiter

| Dienst | Anbieter | Sitz / Ort der Verarbeitung | Daten | Drittland/Garantie | Pflicht? |
|---|---|---|---|---|---|
| Hosting (Server, Datenbank, Uploads) | [Hosting-Anbieter] | [Land, Rechenzentrum EU] | alle oben genannten | – | ja |
| E-Mail-Versand (SMTP) | [Mail-Anbieter, z. B. EU-Region] | [Land] | E-Mail-Adresse, Name, Inhalt der Mails (Einladungen, Passwort-Links, Erinnerungen) | [–/SCC] | ja |
| Fehleranalyse | [Sentry: Functional Software, Inc., USA, EU-Region Frankfurt] bzw. selbst betrieben | [EU (de.sentry.io)] | technische Fehlerberichte ohne IP-Adresse, ohne Formularinhalte; E-Mail-Adressen entfernt | [DPF/SCC bei US-Region] | **optional** (nur mit `SENTRY_LARAVEL_DSN`) |
| Videohosting | [Videohoster] | [Land] | IP-Adresse und Geräteangaben beim Abspielen | [ggf. SCC] | ja, sobald echte Videos hinterlegt sind (`PRIVACY_VIDEO_PROVIDER`) |
| Externer Sicherungsspeicher | [Speicheranbieter, z. B. S3-kompatibel] | [Land EU] | vollständige Datensicherung (14 Tage) | – | empfohlen (`BACKUP_DISK`) |
| B2B-Shop (WooCommerce) | [Shop-Hosting] / Betreiber | [Land] | nur Shopkonten der Schulen (Mitarbeitende), keine Lerndaten | – | nur mit Shop-Anbindung; Verarbeitung für eigene Zwecke des Betreibers, siehe [vvt.md](vvt.md) |

Keine Auftragsverarbeiter, aber Empfänger beim Aufruf durch die Lernenden selbst:
OpenStreetMap Foundation (Kartenkacheln bei der Praxisanmeldung, Browser lädt
direkt) und der Anbieter des jeweiligen Browsers bei freiwilliger
Spracherkennung (Web Speech API). Have I Been Pwned erhält beim Setzen eines
Passworts nur die ersten fünf Zeichen eines Hashes (kein Personenbezug).

## Anlage 2: Technische und organisatorische Maßnahmen

Siehe [tom.md](tom.md) in der jeweils gültigen Fassung.
