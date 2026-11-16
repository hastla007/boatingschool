# Connectoren: Aufgaben aus der Prüfung vom 16.11.2026 und Entscheidung 17

Stand: 16.11.2026. Aufgabenliste mit den Antworten des Inhabers vom 16.11.2026 (siehe „Entscheidungen des Inhabers“).

## Entscheidungen des Inhabers (16.11.2026)

- **Entscheidung 17 gilt in der Fassung „eigenes Lizenzprodukt“** (unten beschrieben). Die zwischenzeitlich notierte Fassung „in PRO enthalten, kein eigenes Lizenzprodukt“ (Branch `claude/youthful-bardeen-7ejd7a`, `docs/captain-connectoren.md`) gilt **nicht**.
- **BS-01: nein.** Der WooCommerce-Connector fällt nicht unter Entscheidung 17 und bekommt keine Lizenzprüfung. BS-02 entfällt.
- **BS-03: Kundenspezifische Connectoren liegen immer im Repository des Kunden.** Der Form-Connector für BoatingSchool entsteht also in diesem Repository (`hastla007/boatingschool`, unter `integrations/`).

## Worum es geht

**Entscheidung 17 des Inhabers (16.11.2026, verbindlich):** Jeder Connector von BoatingSchool und LearnBoating zu
einem Captain-Plugin (Form Captain, Voucher Captain, Affiliate Captain, Pass Captain, künftige) ist ein **eigenes,
nicht verkauftes Projektprodukt** auf dem Lizenzserver des Inhabers:

1. eigener Produktschlüssel, eigenes Präfix für die Rechte, eigene Lizenz;
2. eigenes Plugin, getrennt installiert; nicht Teil eines Pro-Add-ons und von keiner Pro-Lizenz abgedeckt;
3. Paket und Updates nur für das jeweilige Projekt, über den Lizenzserver;
4. ohne gültige Lizenz macht der Connector nichts Neues (fail-closed); Bestehendes bleibt;
5. Anmeldung am Lizenzserver über die Katalogdatei `wplc-catalog.json` im Plugin-Ordner, nicht über ein MU-Plugin.

Alle Preise des Inhabers sind netto zuzüglich Umsatzsteuer. In diesem Repository steht kein solcher Preis.

**Ergebnis der Prüfung für BoatingSchool** (alle 27 Zweige außer `dependabot/*`, Stand `main` = `9c8cc95`):

- Es gibt **keinen** Connector zu einem Captain-Plugin, in keinem Zweig. Kein Treffer für `vcap_`, `wpac_`,
  `wpcap_`, `wplc` oder „Captain“ (außer Bildern `captain-laptop.webp`).
- Der einzige Connector ist `integrations/woocommerce-connector` („Bootsschul-Plattform Connector“, 1.3.0). Er
  verbindet WooCommerce mit der eigenen Laravel-Plattform, nicht mit einem Captain-Plugin. Er hat keine Lizenzprüfung
  und keinen Updater.
- Der geplante Form-Connector existiert nirgends, auch nicht als Plan.
- `hastla007/boatingschool` ist ein **öffentliches** GitHub-Repository (am 16.11.2026 nachgesehen). Der Quelltext des
  WooCommerce-Connectors (`License: Proprietary`) ist also öffentlich lesbar. Echte Schlüssel oder Tokens liegen
  nicht im Connector-Ordner.

**So liest man die Aufgaben:** Nummer, Schwere (`[KRITISCH]`, `[WICHTIG]`, `[OPTIMIERUNG]`), Was, Wo (Datei:Zeile und
Zweig), wann erledigt, was sie braucht. Kritische Aufgaben gibt es hier keine.

## Dieses Repository (`hastla007/boatingschool`)

### Bestehender WooCommerce-Connector

- [x] **BS-01 · erledigt 16.11.2026: Antwort „nein“ · [WICHTIG] · Frage an den Inhaber: Fällt der WooCommerce-Connector unter Entscheidung 17?**
  **Was:** Der Inhaber sagt, ob `integrations/woocommerce-connector` (WooCommerce ↔ eigene Laravel-Plattform, kein
  Captain-Plugin) ein lizenziertes Projektprodukt nach Entscheidung 17 werden soll. **Empfehlung: nein.** Die
  Entscheidung nennt Connectoren zu Captain-Plugins (Form, Voucher, Affiliate, künftige); dieser Connector hat mit
  keinem Captain-Plugin zu tun, und eine Lizenzsperre würde den eigenen Shop treffen (siehe BS-02). Belegt ist diese
  Lesart nicht – deshalb die Frage.
  **Wo:** `main`: `integrations/woocommerce-connector/boatingschool-connector.php:6-15` (Plugin-Kopf, Version 1.3.0,
  `Requires Plugins: woocommerce`, `License: Proprietary`). Antwort eintragen in dieser Datei und in
  `docs/commerce/README.md`, Abschnitt „Entscheidungen“.
  **Erledigt, wenn:** die Antwort mit Datum in `docs/commerce/README.md` steht.
  **Braucht:** Entscheidung des Inhabers.

- [x] **BS-02 · entfällt (BS-01 = „nein“) · [WICHTIG] · Nur falls BS-01 „ja“: WooCommerce-Connector lizenzieren.**
  **Was:** Add-on-Kernel und Lizenzclient einbauen, Produktschlüssel (Vorschlag `boatingschool-shop-connector`),
  eigenes Präfix (Vorschlag `boatingschool_shop`; nur Kleinbuchstaben, Ziffern, Unterstrich), `wplc-catalog.json`,
  Updater. Fail-closed ist hier heikel: Bezahlte Bestellungen müssen weiter zugestellt und Stornos weiter gesperrt
  werden; sperren dürfte man höchstens neue Käufe an der Kasse.
  **Wo:** `main`: `integrations/woocommerce-connector/` (rund 4.700 Zeilen), Tests `tests/Unit/Connector*Test.php`.
  **Erledigt, wenn:** Tests beide Zustände zeigen (mit und ohne Lizenz) und ohne Lizenz keine bezahlte Bestellung
  liegen bleibt.
  **Braucht:** BS-01 = „ja“; den Lizenzserver des Inhabers (Hostname, Schlüssel); Kernel aus dem privaten
  `captain-pro` – der dürfte in diesem **öffentlichen** Repository nicht liegen (siehe BS-03).
  Bei BS-01 = „nein“ entfällt diese Aufgabe; dann hier abhaken mit dem Vermerk „entfällt“.

- [x] **BS-04 · erledigt 16.11.2026 (#99, Plugin 1.4.0) · [OPTIMIERUNG] · Geheimnisse standardmäßig nur aus Konstanten.**
  **Was:** Der Connector erlaubt Geheimnisse (Schlüssel für die signierten Aufrufe) standardmäßig auch aus der
  Datenbank. Sicherer Standard: nur aus Konstanten in `wp-config.php`; die Datenbank nur, wenn der Betreiber es
  ausdrücklich einschaltet.
  **Wo:** `main`: `integrations/woocommerce-connector/includes/class-bs-settings.php:99-105` (`allow_db_secrets()`,
  Standard „ja“, Filter `bs_connector_allow_db_secrets`).
  **Erledigt, wenn:** der Standard „nein“ ist, bestehende Installationen beim Update nicht ausgesperrt werden
  (Übergang beschrieben) und `tests/Unit/ConnectorAccountsAndSettingsTest.php` beide Fälle prüft.
  **Braucht:** nichts von außen. Kein Bezug zu Entscheidung 17.

- [x] **BS-05 · erledigt 16.11.2026 (#101, Workflow `connector-wordpress.yml`) · [OPTIMIERUNG] · Ein Test des Connectors in echtem WordPress mit WooCommerce.**
  **Was:** Die fünf Connector-Tests laufen gegen eine Nachbildung von WordPress und WooCommerce. Ein Lauf in einem
  echten WordPress fehlt in der CI (eine Anleitung von Hand gibt es: `docs/commerce/wordpress-integrationstest.md`).
  **Wo:** `main`: `tests/Unit/connector-wp-harness.php`, `tests/Unit/connector-wc-harness.php`,
  `tests/Unit/Connector*Test.php`, `.github/workflows/tests.yml:122`.
  **Erledigt, wenn:** ein CI-Job den Connector in WordPress mit WooCommerce aktiviert und mindestens eine Bestellung
  bis zur Zustellung prüft.
  **Braucht:** nichts von außen. Kein Bezug zu Entscheidung 17.

### Geplanter Form-Connector (`boatingschool-form-connector`, Form Captain)

- [x] **BS-03 · erledigt 16.11.2026: Ort = dieses Repository (Kunden-Repo), `integrations/boatingschool-form-connector/` · [WICHTIG] · Ort und Namen für den Form-Connector festlegen.**
  **Was:** Vermerken, wo `boatingschool-form-connector` entsteht und wie er heißt. Fest stehen: eigenes Plugin,
  Produktschlüssel `boatingschool-form-connector`, Präfix `boatingschool_form`, Recht `boatingschool_form.leads`,
  eigene `wplc-catalog.json`, fail-closed (ohne Lizenz keine neue Übergabe von Anfragen; schon übergebene bleiben),
  Paket und Updates nur über den Lizenzserver, nicht Teil von Form Captain Pro. Offen ist der Ort: (a) dieses
  Repository unter `integrations/`, (b) `hastla007/boatingschool-wp`, (c) ein eigenes privates Repository.
  Dieses Repository ist öffentlich. Ein lizenzierter Connector bringt den Add-on-Kernel aus dem privaten
  `captain-pro` mit; in einem öffentlichen Repository wäre der Kernel für jeden lesbar und die Lizenzschranke
  entfernbar. Genau das ist bei LearnBoating passiert (dort Aufgabe LB-07). Deshalb Empfehlung: (c), oder dieses
  Repository vorher privat stellen.
  **Wo:** noch kein Code, in keinem Zweig. Eintrag in dieser Datei; Gegenstück im Repository von Form Captain
  (`wp-formmaster`, `docs/ENTSCHEIDUNGEN.md`).
  **Erledigt, wenn:** der Ort feststeht und hier steht. Kein Code.
  **Braucht:** Entscheidung des Inhabers zum Ort und zur Sichtbarkeit des Repositorys.
  **Antwort (16.11.2026):** Kundenspezifische Connectoren liegen immer im Kunden-Repository, hier also unter
  `integrations/boatingschool-form-connector/`. **Offen bleibt:** Solange dieses Repository öffentlich ist, wäre ein
  dort eingebauter Add-on-Kernel aus `captain-pro` für jeden lesbar. Vor dem ersten Code für den Form-Connector das
  Repository auf privat stellen oder den Kernel nur als Abhängigkeit beim Bau einbinden (nicht einchecken).

### Lead-Eingang für Form Captain (aus `wp-formmaster` P8-43 bis P8-50, angehängt am 16.11.2026)

Vertragsentwurf aus Sicht von Form Captain: `hastla007/wp-formmaster`, `docs/spec/projekt-connectoren-vertraege.md`, Teil A (Endpunkt, HMAC, JSON-Schema `form-captain-lead/1`, Idempotenz, Fehlercodes, Rate-Limits, Datenschutz). Am Code bestätigt (d152f21): `/api/commerce/v1` hat keinen Lead-Eingang (P8-41); der Endpunkt entsteht neu und getrennt. Alle Punkte brauchen die Freigabe des Inhabers für den Pilot (P8-40).

- [ ] **BS-06 · [WICHTIG] · Lead-Datenmodell und Inbox planen (P8-43).** Lead = unqualifizierter Interessent, keinem Tenant zugeordnet, weder Benutzer noch Shopkonto noch Schuldatensatz. Status (neu, in Bearbeitung, erledigt, abgelehnt), Aufbewahrung, Löschung, Superadmin-Rollen festlegen.
- [ ] **BS-07 · [WICHTIG] · Vertrag übernehmen und versionieren (P8-42).** Teil A des Entwurfs hier als eigene Doku übernehmen, Schema-Version festschreiben; Abweichungen an `wp-formmaster` zurückmelden.
- [ ] **BS-08 · [WICHTIG] · Endpunkt `POST /api/integrations/v1/form-captain/leads` (P8-44).** Eigener HMAC-Schlüssel (nicht der Commerce-Schlüssel), Zeitstempel und Nonce, Replay-Schutz, eigener Rate-Limit-Topf, Schemaprüfung mit Fehlercodes, Idempotenz über den Schlüssel aus Website, Formular und Entry.
- [ ] **BS-09 · [WICHTIG] · Lead-Inbox im Superadmin (P8-45).** Anzeigen, bearbeiten, ablehnen, löschen; der Intake legt nie Konto, Schule oder Code an.
- [ ] **BS-10 · [WICHTIG] · WordPress-Connector `integrations/boatingschool-form-connector/` (P8-46, P8-47).** Form Captain und Contract-Version erkennen, Endpoint und Secret nur serverseitig, standardmäßig aus; nur explizit gemappte, nicht sensible Felder plus Consent-Referenz, Formular-/Entry-ID und erlaubte Quell-URL. Ort und Sichtbarkeit siehe BS-03 (Repository vorher privat oder Kernel nur beim Bau einbinden).
- [ ] **BS-11 · [WICHTIG] · Lizenzprüfung des Connectors.** Nach der Fassung von Entscheidung 17 in diesem Repository (eigenes Produkt `boatingschool-form-connector`, Recht `boatingschool_form.leads`). Entscheidung 17 ist am 16.11.2026 endgültig bestätigt: eigenes Produkt mit eigenem Präfix, nicht Teil von Form Captain Pro (`wp-formmaster` P8-88; P8-90 dort überholt).
- [ ] **BS-12 · [WICHTIG] · Zustellstatus und Wiederholung über die Outbox von Form Captain (P8-48).** Remote-Lead-ID, letzter Versuch, wiederholbarer/endgültiger Fehler, manuelle Wiederholung; eine erfolgreiche Wiederholung erzeugt keinen zweiten Lead.
- [ ] **BS-13 · [WICHTIG] · Gemeinsame Tests und Staging-Abnahme (P8-49, P8-50).** HMAC-Kompatibilität, Replay, falsches Schema, Doppelzustellung, Timeout nach erfolgreichem Schreiben, Schlüsselrotation, Rate-Limit, Superadmin-Sichtbarkeit; danach WordPress → Laravel Ende-zu-Ende auf Staging, Testleads löschen, Datenschutzabnahme dokumentieren.

## Repository `hastla007/boatingschool-wp`

Für dieses Repository gab es keinen Schreibzugriff; die Aufgabe steht deshalb hier. Es enthält nur drei Textdateien
(`README.md`, `texte/homepage/konzept.md`, `theme/konzept.md`), keinen Code und keinen Widerspruch zu Entscheidung 17.

- [ ] **BSW-01 · [OPTIMIERUNG] · Hinweis auf Entscheidung 17 im Theme-Konzept.**
  **Was:** Sobald ein Captain-Plugin für die BoatingSchool-Website eingeplant wird, im Abschnitt zum Connector einen
  Satz ergänzen: Connectoren zu Captain-Plugins sind eigene, lizenzierte Projektprodukte (eigener Schlüssel, eigenes
  Präfix, Katalogdatei), nicht Teil eines Pro-Add-ons.
  **Wo:** `hastla007/boatingschool-wp`, `main`: `theme/konzept.md`, um Zeile 1109.
  **Erledigt, wenn:** der Satz steht.
  **Braucht:** Schreibzugriff auf `boatingschool-wp`; hängt an BS-03 (falls der Form-Connector dort entsteht).
  Die Angaben „inkl. MwSt.“ und „Bruttopreis“ in `theme/konzept.md:117`, `:151`, `:907` betreffen Endkundenpreise
  des Shops und sind **kein** Widerspruch zu „alle Preise des Inhabers netto“.

## Anzahl

6 Aufgaben: 5 für dieses Repository (BS-01 bis BS-05), 1 für `boatingschool-wp` (BSW-01). 3 wichtig, 3 Optimierung,
keine kritisch.

**Stand 16.11.2026:** BS-01, BS-03, BS-04 und BS-05 erledigt, BS-02 entfällt. Offen bleibt nur BSW-01 (bedingt: erst wenn ein
Captain-Plugin für die BoatingSchool-Website eingeplant wird).

## Was die Prüfung nicht geklärt hat

- Die 10 `dependabot/*`-Zweige wurden nicht gelesen (nur Abhängigkeiten).
- Der Connector (rund 4.700 Zeilen) wurde nur auf Recht, Nonce, Geheimnisse und Protokoll geprüft, nicht in der Tiefe.
- Kein CI-Lauf wurde gesehen und kein Test ausgeführt.
