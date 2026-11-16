> **ENTWURF – vor Verwendung anwaltlich prüfen lassen; keine Rechtsberatung**

# Rechtliche Unterlagen (Entwürfe)

Diese Entwürfe beschreiben die Lernplattform so, wie sie im Code umgesetzt ist
(Stand der jeweiligen Datei im Repository). Sie sind Arbeitsgrundlage für
Anwältin/Anwalt und Datenschutzbeauftragte(n), **keine** fertigen Verträge.
Platzhalter in eckigen Klammern (`[Betreiber]`, `[Anschrift]`,
`[Hosting-Anbieter]` …) stehen für Angaben, die der Code nicht kennt.

Rollen:

- **Betreiber** = Plattformunternehmen; für die Lerndaten **Auftragsverarbeiter**
  der Schulen, für eigene Zwecke (Shop, Vertragsverwaltung, Plattformseite,
  Superadmin) **Verantwortlicher**.
- **Bootsschule** = Kundin, **Verantwortliche** für die Daten ihrer Lernenden und
  ihres Teams.
- **Lernende** = betroffene Personen, teils **minderjährig (ab 14 Jahren)**.

| Datei | Inhalt | Wer muss noch ran |
|---|---|---|
| [avv-vorlage.md](avv-vorlage.md) | Auftragsverarbeitungsvertrag Betreiber ↔ Bootsschule (Art. 28 DSGVO) mit Anlagen Unterauftragsverarbeiter und TOM | Anwalt (Haftung, Kontrollrechte, Vergütung von Unterstützungsleistungen), DSB (Unterauftragsverarbeiter mit echten Namen und AVV) |
| [tom.md](tom.md) | Technische und organisatorische Maßnahmen, so wie umgesetzt; Lücken als „offen“ | DSB/IT (Hosting-Maßnahmen, Zutritt, Verschlüsselung ruhender Daten, Notfallplan) |
| [vvt.md](vvt.md) | Verzeichnis von Verarbeitungstätigkeiten (Art. 30 Abs. 1 und 2 DSGVO) | DSB (Vollständigkeit, Rechtsgrundlagen, Fristen bestätigen) |
| [loeschkonzept.md](loeschkonzept.md) | Fristen und Löschmechanismen je Datenart, mit Konfigurationsschlüsseln | DSB + Anwalt (Aufbewahrungspflichten, Nachweise zu Prüfungen) |
| [agb-b2b-shop.md](agb-b2b-shop.md) | AGB für den Code-Shop (B2B) | Anwalt (komplett; Preise, Zahlung, Haftung, Gerichtsstand) |
| [nutzungsbedingungen-lernende.md](nutzungsbedingungen-lernende.md) | Nutzungsbedingungen für Lernende (du-Form) | Anwalt (Minderjährige, Verhältnis Schule ↔ Betreiber) |
| [barrierefreiheit.md](barrierefreiheit.md) | Erklärung zur Barrierefreiheit (WCAG 2.1 AA, bekannte Lücken) | Prüfung durch Fachstelle; Anwalt (Anwendbarkeit BFSG) |

## Was der Code schon liefert

- **Datenschutzerklärung je Schule**: zentrale Vorlage in
  `app/Support/LegalPages.php` (Superadmin → Website), mit konfigurations­abhängigen
  Abschnitten aus `app/Support/PrivacyNotices.php`: Videoanbieter
  (`PRIVACY_VIDEO_PROVIDER`, `_COUNTRY`, `_URL`), Fehleranalyse (nur mit
  `SENTRY_LARAVEL_DSN`), Datensicherung (`BACKUP_KEEP_DAYS`, `BACKUP_DISK`,
  `PRIVACY_BACKUP_PROVIDER`, `PRIVACY_BACKUPS_ENCRYPTED`), Code-Shop (nur mit
  Shop-Anbindung) und Speicherdauer je Datenart (aus `config/data_retention.php`).
  Siehe `config/privacy.php`.
- **Löschroutinen**: `app:prune-personal-data` (täglich, Mobile-API stündlich) und
  `app:prune-inactive-learners` (täglich, nur mit
  `DATA_RETENTION_INACTIVE_LEARNER_MONTHS`), Kontolöschung über
  `App\Services\AccountErasureService`.
- **Auskunft/Export**: Profil → Daten exportieren (JSON), App-Export
  (`POST /api/v1/profile/export`), Export einer ganzen Schule bei Vertragsende.

## Offene Punkte für Anwalt/DSB (Auswahl)

1. **Minderjährige (14–17 Jahre)**: Vertragsschluss und Einwilligungen (z. B.
   Spracherkennung im Browser) – genügt die Einbindung über die Bootsschule? Braucht
   es Hinweise für Erziehungsberechtigte?
2. **Aufbewahrung von Lern- und Prüfungsnachweisen**: Müssen Schulen Nachweise
   (z. B. Teilnahme, Praxisanmeldung) aufbewahren? Davon hängen
   `DATA_RETENTION_ATTEMPT_*`, `DATA_RETENTION_AUDIT_LOG_*` und
   `DATA_RETENTION_INACTIVE_LEARNER_MONTHS` ab (alle derzeit aus bzw. leer).
3. **Unterauftragsverarbeiter**: echte Anbieter eintragen, AVV/DPA abschließen,
   Drittlandtransfers prüfen (Sentry SaaS USA, Videohoster).
4. **Shop**: Rollenverteilung (Betreiber als Verkäufer und Verantwortlicher für
   Shopkonten), Rechnungsaufbewahrung (§ 147 AO, § 257 HGB) im Shop-System.
5. **Haftung für Lerninhalte** (Prüfungsfragen der Behörden, Erklärungen,
   Navigationsaufgaben) gegenüber Schulen und Lernenden.
6. **Barrierefreiheit**: Ob das Barrierefreiheitsstärkungsgesetz (BFSG) greift
   (Dienstleistung im elektronischen Geschäftsverkehr gegenüber Verbrauchern?),
   und Prüfung durch eine Fachstelle.
7. **DSFA**: Ob eine Datenschutz-Folgenabschätzung nötig ist (Minderjährige,
   Lernverhalten, Prüfungsergebnisse) – Schwellwertanalyse durch DSB.
