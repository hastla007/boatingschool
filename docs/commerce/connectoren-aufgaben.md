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

## Workflow-Connector (WF-CUST1, übernommen am 16.11.2026)

Übernommen aus `hastla007/wp-workflowmaster`, `docs/UEBERGABE-E17-kundenconnectoren.md`, Abschnitt „Ziel:
`hastla007/boatingschool`“ (Entscheidung 17: eigenes Projektprodukt, nicht Teil von Workflow Captain Pro). Die Liste
unten steht unverändert wie dort; Stand und Ort der Umsetzung darunter. Vertrag und Betrieb:
[`../workflow/README.md`](../workflow/README.md). Konzept: `wp-workflowmaster`, `konzept.md`, Abschnitt 17.10.

- [ ] WF-CUST1-01 – Vorhandenen WooCommerce-Connector als fachlichen Owner von Paid-Order-Codezustellung, Idempotenz pro Order-Position, Retry und Refund-Sperrung festhalten; Workflow darf diese Befehle nicht nochmals ausführen.
- [ ] WF-CUST1-02 – In BoatingSchool einen eigenen dauerhaften Workflow-Event-Outbox-Dispatcher nach Commit ergänzen. Nicht die vorhandene `tenant.provision`-Shop-Outbox oder bloße Commerce-Logs als allgemeine Zustellgarantie voraussetzen.
- [ ] WF-CUST1-03 – Versionierte Events für Shopkonto registriert/verknüpft/deaktiviert, Provisionierung erfolgreich/fehlgeschlagen sowie Code-Batch geliefert/entwertet veröffentlichen; stabile Event-IDs und tenant-/shop-scoped Correlation-IDs nutzen.
- [ ] WF-CUST1-04 – Allowlist für Event-Payloads implementieren: Referenzen, Status, Menge und Kurs-/Produktcode; nie Gutscheinwerte, Linkcodes, Passwörter, unbestätigte E-Mail-/USt-Daten oder vollständige Lern-/Kontodaten versenden.
- [ ] WF-CUST1-05 – Separaten Workflow-Vertrag und Credentials mit eigener Key-ID, HMAC, Zeitfenster, Nonce/Replay-Schutz, Rotation, Rate-Limits und Contract-Handshake entwickeln; bestehende Commerce-Keys und `/api/commerce/v1`-Scopes nicht wiederverwenden.
- [ ] WF-CUST1-06 – Tenant-Scope serverseitig authentifizieren und RLS-Kontext pro Anfrage setzen; Tenant-IDs aus Ereignissen gelten nur als Referenz. Plattformweite Rechte als separaten, überprüfbaren Scope führen.
- [ ] WF-CUST1-07 – Begrenzte App-Aktionen nur über öffentliche Services zulassen: tenant-scoped Schuladmin-Hinweis und optional Gruppenmitglied-Einladung mit Rollenprüfung, Sitzplatz-/Tageslimits und Command-Idempotenz. Account-Freigabe/-Verknüpfung, Codeausgabe/-widerruf und Kursrechte bleiben ausgeschlossen.
- [ ] WF-CUST1-08 – BoatingSchool-Provider im WordPress-Connector/Workflow-Captain über Version, Status, Testevent, Payload-Mapping, Eventzustellung, Retry und Fehlerdiagnose sichtbar machen.
- [ ] WF-CUST1-09 – Integrationstests für Tenant-Isolation, RLS, falsche/abgelaufene Signatur, Replay, doppelte Event-ID, HMAC-Key-Rotation, Timeout nach erfolgreichem Zielaufruf, Refund, Code-Redaktion und Outbox-Replay ergänzen.
- [ ] Neu (Entscheidung 17) – Produktschlüssel, Präfix und Lizenz des BoatingSchool-Workflow-Connectors auf dem Lizenzserver des Inhabers anlegen; Lizenzprüfung im Connector-Plugin mit eigener Kopie der Lizenzbibliothek; Paket und Updates nur für diesen Kunden.

### Stand der Umsetzung (Branch `claude/plugin-todo-liste-2a83v4`, noch nicht in `main`)

Die Kästchen oben bleiben offen, bis der Code in `main` ist und die unten genannten Prüfungen in echten Umgebungen
gelaufen sind.

| Aufgabe | Stand | Wo | Offen |
|---|---|---|---|
| WF-CUST1-01 | Code und Doku fertig | `docs/workflow/README.md` (Abschnitt WF-CUST1-01), `App\Workflow\ActionRegistry::FORBIDDEN`, `WorkflowKeys` (getrennte Schlüssel), Plugin ohne solche Aktion | – |
| WF-CUST1-02 | Code fertig, lokal getestet (PostgreSQL) | Migration `2024_02_01_001100_create_workflow_tables`, `App\Workflow\WorkflowOutbox`, `WorkflowDispatcher`, `app:workflow-outbox` (Scheduler jede Minute) | Betrieb: Scheduler-Lauf in Produktion beobachten |
| WF-CUST1-03 | Code fertig | `App\Workflow\EventCatalog`, `DomainEvents`; Aufrufe in `ShopAccounts`, `ShopOrders`, `Commerce\Outbox` | – |
| WF-CUST1-04 | Code fertig | `App\Workflow\PayloadAllowlist`, Plugin `BSW_Payload_Mapper` | Datenschutzbewertung (D8, Rechtsprüfung) |
| WF-CUST1-05 | Code fertig | `WorkflowSignature`, `WorkflowKeys`, `VerifyWorkflowSignature`, Handshake `/api/integrations/v1/workflow/handshake` | Schlüssel erzeugen und hinterlegen |
| WF-CUST1-06 | Code fertig, RLS lokal mit beiden Rollen getestet | `App\Workflow\CommandHandler` | – |
| WF-CUST1-07 | Code fertig | `SchoolAdminNoticeAction` (an), `LearningGroupInviteAction` (aus) | Inhaber: Gruppeneinladung freigeben? Heute trägt kein Ereignis eine Nutzer-Referenz; die Aktion braucht alle Kennungen in der Schritt-Konfiguration |
| WF-CUST1-08 | Code fertig, gegen echtes WordPress mit echtem Workflow Captain und Workflow Captain Pro geprüft (`tests/workflow-e2e/run.sh`, 16.11.2026, 46 Prüfungen) | `integrations/boatingschool-workflow-connector/` 0.2.0: Version, Laufzeit (F-18a: „Workflow Captain Pro fehlt oder das Recht fehlt“), Anmeldung/Freigabe, Testereignis, Payload-Mapping, Zustellung/Doppel, Wiederholung, Fehlerdiagnose | Statusseite im Browser (Tastatur, Screenreader); Lauf mit WordPress 7.x/WooCommerce 11.x und echter Lizenzbibliothek |
| WF-CUST1-09 | fertig für App und Plugin; Ende zu Ende über das Netz geprüft | `tests/Feature/Workflow/*` (PostgreSQL 16, beide Rollen, RLS), `tests/Unit/WorkflowConnector*Test.php`, `tests/workflow-e2e/run.sh` (App ↔ WordPress ↔ Workflow Captain: Tenant/RLS beim Befehl, falsche/abgelaufene Signatur, Replay, Doppel, Schlüsselwechsel, Timeout nach erfolgreichem Aufruf, Erstattung, Code-Redaktion, Outbox-Replay). Gefunden und behoben: Befehle waren mit der ausgelieferten Konfiguration immer `ACTION_DISABLED` (`ActionRegistry`) | E2E als CI-Job (Muster `connector-wordpress.yml`); Staging |
| Neu (E17) | vorbereitet | `wplc-catalog.json` (Status „planned“), `BSW_License` (fail-closed, Bibliothek aus dem Paketbau unter `lib/`) | Produkt auf dem Lizenzserver anlegen; Paketbau mit eigener Kopie der Lizenzbibliothek (nicht im öffentlichen Repository, siehe BS-03); Paket und Updates nur für diesen Kunden |

In `wp-workflowmaster`, `TODO.md`, WF-E17-02 ist als Ort dieser Aufgaben `hastla007/boatingschool`,
`docs/commerce/connectoren-aufgaben.md`, Abschnitt „Workflow-Connector (WF-CUST1 …)“ einzutragen (dort, nicht hier).
