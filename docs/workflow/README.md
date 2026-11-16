# Workflow-Vertrag: Plattform ↔ BoatingSchool Workflow Connector

Stand: 16.11.2026. Aufgaben WF-CUST1-01 bis -09 (übernommen aus `hastla007/wp-workflowmaster`,
`docs/UEBERGABE-E17-kundenconnectoren.md`; Stand und offene Punkte in
[`../commerce/connectoren-aufgaben.md`](../commerce/connectoren-aufgaben.md), Abschnitt „Workflow-Connector“).

## Worum es geht

Die Plattform meldet ausgewählte fachliche Vorgänge als **versionierte Ereignisse** an ein eigenes WordPress-Plugin, den
**BoatingSchool Workflow Connector** (`integrations/boatingschool-workflow-connector/`). Das Plugin gibt sie an
[Workflow Captain](https://github.com/hastla007/wp-workflowmaster) weiter (öffentlicher Connector-Vertrag v1 des freien
Kerns). Ein Workflow kann umgekehrt wenige, eng begrenzte **Befehle** an die Plattform schicken.

- **Eigenes Projektprodukt (Entscheidung 17):** Produktschlüssel `boatingschool-workflow-connector`, Präfix
  `boatingschool_workflow`, Recht `boatingschool_workflow.connector` (`wplc-catalog.json` im Plugin, Status
  „planned“, bis das Produkt auf dem Lizenzserver angelegt ist). Das Plugin prüft seine Lizenz selbst; eine Pro-Lizenz
  eines Captain-Produkts deckt es nicht ab. Die Lizenzbibliothek liegt **nicht** im (öffentlichen) Repository, der
  Paketbau legt sie unter `lib/license-client.php` ab (siehe BS-03).
- **Laufzeit: Workflow Captain Pro mit dem Recht `workflow.custom_connectors`** (Entscheidung des Inhabers F-18a,
  16.11.2026). Der Connector nutzt den öffentlichen Connector-Vertrag (`workflow_captain_register_connectors_v1`,
  `workflow_captain_actions_v1`, `Consumer::capture()`); Workflow Captain fragt dabei den Filter
  `workflow_captain_connector_runtime_allowed_v1`, den Pro mit dem Recht schaltet. Ohne Pro meldet sich das Plugin
  nicht an (`workflow_captain_pro_missing`), ohne Recht lehnt Workflow Captain ab (`runtime_not_allowed`); Ereignisse
  der App bekommen dann `503` mit diesem Code und werden wiederholt, Aktionen enden mit `runtime_not_allowed`.
  Statusseite und Admin-Hinweis: „Workflow Captain Pro fehlt oder das Recht fehlt“. Das ist eine technische
  Abhängigkeit, keine Lizenzdeckung: Die eigene Lizenz prüft das Plugin selbst (Entscheidung 17).
- **Getrennt von der Commerce-API:** eigene Endpunkte, eigene Schlüssel, eigene Header (`X-BSW-…`), eigene
  Ausgangstabelle. Die Shop-Outbox `tenant.provision` (`commerce_event`) bleibt unberührt.

## WF-CUST1-01: Was der WooCommerce-Connector besitzt

Der WooCommerce-Connector (`integrations/woocommerce-connector`, Commerce-API v1, [`../commerce/README.md`](../commerce/README.md))
ist der **fachliche Owner** von:

| Vorgang | Wo |
|---|---|
| Zustellung bezahlter Bestellpositionen (Code-Batch je Position) | Plugin `BS_Orders::deliver_item()` → `POST /api/commerce/v1/orders/lines` → `ShopOrders::deliver()` |
| Idempotenz je Bestellposition | eindeutig `(shop_id, order_id, order_line_id)` in `coupon_batch`, Fingerabdruck der Anfrage, Advisory-Lock |
| Wiederholung | Plugin: Action Scheduler mit `BS_Delivery_Policy`, zweiter Versuch fragt zuerst nach (`GET /orders/…/lines/…`) |
| Sperre bei Rückerstattung/Storno | Plugin `BS_Orders::on_refund()`/`on_cancel()` → `POST /batches/{id}/void` mit `Idempotency-Key` → `ShopOrders::void()` |
| Freigabe/Verknüpfung von Shopkonten | Superadmin bzw. Verknüpfungscode (`ShopAccounts::confirmLink()`) |

Ein Workflow **führt keinen dieser Befehle aus**. Er erfährt nur, dass sie geschehen sind (`code_batch.delivered`,
`code_batch.revoked`, `shop_account.*`). Durchgesetzt in drei Schichten:

1. Die App lehnt solche Befehle ab (`ACTION_FORBIDDEN`, `App\Workflow\ActionRegistry::FORBIDDEN`), auch wenn eine
   spätere Version sie versehentlich registriert (`register()` wirft dann).
2. Workflow-Schlüssel gelten nicht für die Commerce-API und umgekehrt (`App\Workflow\WorkflowKeys`: gleiche ID oder
   gleiches Geheimnis wie ein Commerce-Schlüssel wird nie angenommen).
3. Das Plugin bietet keine solche Aktion an (`BSW_Manifest::ACTIONS`).

Tests: `WorkflowCommandTest::test_platform_scope_and_actions_owned_by_the_woocommerce_connector_are_refused`,
`WorkflowInboundSecurityTest::test_workflow_and_commerce_credentials_never_cross`,
`WorkflowConnectorContractTest::test_no_action_touches_what_the_woocommerce_connector_owns`.

## WF-CUST1-02/-03: Ereignisse und Ausgang

**Entstehen:** Die dokumentierten Services rufen je Vorgang `App\Workflow\DomainEvents` auf, **innerhalb ihrer
Transaktion** (nicht aus Model-Hooks). `WorkflowOutbox::record()` schreibt in `workflow_event` auf derselben
Verbindung; wird die Transaktion zurückgerollt, gibt es kein Ereignis. Ein Fehler beim Vormerken bricht den fachlichen
Vorgang nie ab (Savepoint, `report()`).

**Stabile Event-ID:** UUID aus SHA-256 über Typ, Version, Gegenstand und Diskriminator. Wiederholt der Shop einen
Aufruf, entsteht kein zweites Ereignis (Primärschlüssel, `insertOrIgnore`).

| Typ (Version 1) | Quelle | Gegenstand | Schule | Felder | Diskriminator |
|---|---|---|---|---|---|
| `shop_account.registered` | `ShopAccounts::register()` (nur neu) | shop_account | – (Plattform) | `shop_account_id`, `status` (immer `pending_review`) | einmal |
| `shop_account.linked` | `ShopAccounts::confirmLink()` | shop_account | ja | `shop_account_id`, `tenant_id`, `status` | Schule + Zeitpunkt |
| `shop_account.deactivated` | `ShopAccounts::deactivate()` (nur echter Wechsel) | shop_account | wenn verknüpft | `shop_account_id`, `tenant_id`, `status` | Zeitpunkt |
| `provisioning.succeeded` | `Commerce\Outbox::send()` | shop_account | ja | `shop_account_id`, `tenant_id`, `status` (`done`/`needs_link`) | Provisionierungs-Ereignis |
| `provisioning.failed` | `Commerce\Outbox::failed()` | shop_account | ja | `shop_account_id`, `tenant_id`, `attempts`, `final` | Provisionierung + Versuch |
| `code_batch.delivered` | `ShopOrders::deliver()` (nur neuer Batch) | code_batch | ja | `batch_id`, `tenant_id`, `shop_account_id`, `order_id`, `order_line_id`, `kind`, `quantity`, `course_key`, `product_key` | einmal |
| `code_batch.revoked` | `ShopOrders::void()` (nur wenn etwas gesperrt wurde) | code_batch | ja | `batch_id`, `tenant_id`, `order_id`, `order_line_id`, `voided`, `redeemed`, `open`, `status` | Idempotency-Key des Stornos (gehasht) |

`correlation_id`: `shop_account:<id>` bzw. `order:<hash(shop_id|order_id)>` (Lieferung und spätere Sperre derselben
Bestellung gehören zusammen). Eine neue Pflichtangabe oder eine geänderte Bedeutung ist eine neue Version des Typs.

**Zustellen:** nach dem Commit (`afterCommit`, Standard: nach der Antwort per `defer()`), spätestens im minütlichen
Scheduler-Lauf `app:workflow-outbox`. `App\Workflow\WorkflowDispatcher` beansprucht mit bedingtem UPDATE
(`pending → sending`), sendet ohne offene Transaktion und schreibt das Ergebnis nur, solange das Ereignis noch diesem
Versand gehört. Bis zu 8 Versuche (1, 5, 15, 60, 180, 360, 720, 1440 Minuten), danach `failed`. Hängende Versände
(> 10 Minuten) zählen als Fehlversuch. In `last_error` steht nur ein Code (`timeout`, `http_503:…`, `rejected:…`).

- **Timeout nach erfolgreichem Aufruf:** erneut mit derselben Event-ID; das Plugin antwortet `duplicate`, das gilt als
  zugestellt.
- **Outbox-Replay:** `php artisan app:workflow-outbox --requeue=<Event-ID>` reiht ein fehlgeschlagenes Ereignis mit
  derselben ID erneut ein.
- Erledigte Ereignisse werden nach 30 Tagen gelöscht (`retention_days`), fehlgeschlagene bleiben.

Umschlag (Body, signiert):

```json
{"op": "event", "contract_version": "1.0.0", "event": {
  "id": "…", "type": "code_batch.delivered", "version": 1, "occurred_at": "2026-11-16T10:00:00Z",
  "scope": "tenant", "tenant_id": "…", "subject": {"type": "code_batch", "id": "…"},
  "correlation_id": "order:…", "data": {"batch_id": "…", "quantity": 2, "course_key": "SBF-SEE", …}}}
```

## WF-CUST1-04: Positivliste

`App\Workflow\PayloadAllowlist` baut die Nutzdaten nur aus den Feldern des Katalogs (`App\Workflow\EventCatalog`) und
prüft jede Wertart (uuid, ref, key, int, bool, enum). Alles andere aus der Quelle fällt weg. **Nie** im Ereignis:
Gutschein- oder Verknüpfungscodes, Passwörter, E-Mail-Adressen, Namen, USt-IDs, Gründe/Freitext, vollständige Lern- oder
Kontodaten. Feldnamen folgen den Regeln für verbotene Werte von Workflow Captain (SPEC-suite-events 5); der Kurs- bzw.
Produktcode der Plattform ist ein öffentlicher Katalogschlüssel und heißt `course_key`/`product_key`. Die Registrierung
meldet `awaiting_link` wie `pending_review`, damit das Ereignis nicht verrät, ob eine Adresse ein App-Konto hat. Das
Plugin prüft dieselbe Liste noch einmal (`BSW_Payload_Mapper`), Workflow Captain baut `data` aus dem Manifest ein
drittes Mal neu auf.

## WF-CUST1-05: Schlüssel, Signatur, Handshake

| | App → Plugin (Ereignisse) | Plugin → App (Handshake, Befehle) |
|---|---|---|
| Endpunkt | `POST https://<shop>/wp-json/boatingschool-workflow/v1/events` | `POST /api/integrations/v1/workflow/handshake`, `…/commands` |
| App-Konfiguration | `WORKFLOW_CONNECTOR_URL`, `WORKFLOW_OUTBOUND_KEY_ID`, `WORKFLOW_OUTBOUND_SECRET` | `WORKFLOW_KEY_ID`/`WORKFLOW_SECRET` (+ `…_NEXT`) |
| Plugin (`wp-config.php`) | `BSW_CONNECTOR_INBOUND_KEY_ID`/`_SECRET` (+ `…_NEXT`) | `BSW_CONNECTOR_APP_URL`, `BSW_CONNECTOR_KEY_ID`, `BSW_CONNECTOR_SECRET` |

- HMAC-SHA256 über `Zeitstempel.Nonce.Body` (wie `InboundGuard::sign()` in Workflow Captain), Header `X-BSW-Key`,
  `X-BSW-Timestamp`, `X-BSW-Nonce`, `X-BSW-Signature`. Methode und Pfad sind nicht signiert; jeder Body nennt seinen
  Zweck (`"op"`), der Empfänger lehnt einen unpassenden ab (`OP_MISMATCH`).
- Feste Prüfreihenfolge: Größe (64 KB) → Schlüssel → Rate-Limit je Schlüssel (App: 60/Min., eigener Topf) →
  Zeitfenster ±300 s → Nonce-Form → Signatur → Replay (App: dauerhaft in `workflow_nonce`; Plugin: Transient) → nur
  JSON, keine Query-Parameter.
- Rotation: zwei aktive Paare; neues Paar eintragen, Gegenseite umstellen, altes entfernen (danach 401).
- Geheimnisse mindestens 16 Zeichen (empfohlen 32+); nie dieselben wie die Commerce-Schlüssel.
- **Handshake:** Das Plugin sendet seine Vertragsversion; die App antwortet mit ihrer, den Ereignistypen (mit Version
  und Feldern) und den freigegebenen Befehlen. Andere Hauptversion: `409 CONTRACT_INCOMPATIBLE`.

## WF-CUST1-06/-07: Befehle

Body: `{"op":"command","action":"school_admin.notice","idempotency_key":"<Schritt-Schlüssel>","scope":"tenant",
"tenant_id":"<Referenz>","subject":{"type":"shop_account","id":"…"},"params":{"template":"codes_delivered"}}`

- **Schule serverseitig:** Die App liest die Schule aus dem Gegenstand (Shopkonto, Code-Batch, Lerngruppe oder die
  Schule selbst) auf der Owner-Verbindung. Die `tenant_id` im Befehl ist nur Referenz; passt sie nicht:
  `403 TENANT_MISMATCH`. Unbekannter Gegenstand: 404. Inaktive Schule: 409.
- **RLS je Befehl:** ausgeführt auf der App-Verbindung (Rolle `boatingschool_app`) mit `TenantContext::set()` genau
  dieser Schule, danach `clear()` (`CommandHandler::runInTenant()`).
- **Plattform-Scope:** eigener, prüfbarer Scope des Schlüssels (`scopes: ["tenant","platform"]`). Version 1 kennt keinen
  plattformweiten Befehl; `scope: platform` wird immer abgelehnt (`SCOPE_NOT_GRANTED` bzw. `PLATFORM_ACTION_UNAVAILABLE`).
- **Idempotenz:** `workflow_command` (eindeutig je Schlüssel-ID und Idempotenzschlüssel). Gleiche Anfrage: gespeichertes
  Ergebnis mit `repeated: true`, kein zweiter Effekt. Anderer Inhalt: `409 IDEMPOTENCY_CONFLICT`. Abgelehnt oder
  fehlgeschlagen: nicht gespeichert, derselbe Schlüssel darf es erneut versuchen.
- **Tagesgrenze** je Schule und Befehl (Kalendertag Europe/Berlin): `429 DAILY_LIMIT_REACHED`.

| Befehl | Standard | Was | Grenzen |
|---|---|---|---|
| `school_admin.notice` | an | Mail an owner/admin der Schule mit fester Vorlage (`codes_delivered`, `codes_revoked`, `shop_account_linked`, `shop_account_deactivated`, `provisioning_failed`); Empfänger ermittelt die App unter RLS | 20/Tag/Schule; kein freier Text, keine Adresse aus dem Workflow |
| `learning_group.invite` | **aus** (`WORKFLOW_ACTION_GROUP_INVITE`) | lädt ein aktives Mitglied der Schule mit bestätigter Adresse über `LearningGroupService::invite()` ein | nur im Namen des aktiven Verwalters der Gruppe; Plätze, Stunden- und Tagesgrenzen des Services; 50/Tag/Schule; Ergebnis ohne Token und Adresse |

Ausgeschlossen bleiben: Freigabe/Verknüpfung von Shopkonten, Ausgabe/Widerruf von Codes, Kursrechte (WF-CUST1-01).

## WF-CUST1-08: das Plugin

`integrations/boatingschool-workflow-connector/` (Version 0.2.0): Anmeldung mit Manifest (Schlüssel
`boatingschool-connector`, Typen `xboatingschoolconnector.<typ>`, alle Felder Datenklasse `normal`, `after_commit: true`),
Ereignis-Eingang (`BSW_Receiver`), Payload-Mapping (`BSW_Payload_Mapper`, Event-ID als Diskriminator), zwei Aktionen
(`TransientFailure` bei Timeout/429/5xx, sonst `PermanentFailure` mit Code), Statusseite unter **Werkzeuge →
BoatingSchool Workflow** mit Version, Lizenz, Anmeldung/Freigabe in Workflow Captain, Einrichtung (nur ob, nie Werte),
Contract-Handshake, Testereignis (`xboatingschoolconnector.connector.tested`), Laufzeit (Workflow Captain Pro und
Recht, F-18a), Payload-Mapping und Fehlerdiagnose
(Zähler und Codes, nie Werte). Antworten an die App: `200 captured|duplicate`, `503 <code>` (vorübergehend: Lizenz,
Workflow Captain fehlt, nicht freigegeben – die App wiederholt), `4xx <code>` (Signatur, Vertrag, Inhalt).

## WF-CUST1-09: Tests

| Test | Prüft |
|---|---|
| `tests/Feature/Workflow/WorkflowOutboxTest` | Ereignisse aus den echten Commerce-Abläufen, Positivliste, Code-Redaktion (keine Codes/Adressen/Namen/USt-ID), doppelte Event-ID, Rollback ohne Ereignis, Zustellung erst nach Commit, Refund-Ereignis |
| `tests/Feature/Workflow/WorkflowDispatcherTest` | Signatur der Zustellung, Timeout nach erfolgreichem Aufruf, Wiederholung mit Abständen, endgültiger Fehler, Outbox-Replay, hängende Versände, Provisionierung fehlgeschlagen/erfolgreich |
| `tests/Feature/Workflow/WorkflowInboundSecurityTest` | falsche/abgelaufene/wiederholte Signatur, unbekannter Schlüssel, Rotation, Rate-Limit, Trennung von der Commerce-API, Handshake |
| `tests/Feature/Workflow/WorkflowCommandTest` | Tenant-Isolation, RLS-Kontext (Rolle, Sichtbarkeit fremder Zeilen), Plattform-Scope, verbotene Befehle, Idempotenz, Tagesgrenze, Gruppeneinladung mit Rollenprüfung |
| `tests/Unit/WorkflowConnectorContractTest` | gleiche Signatur App/Plugin/InboundGuard, gleiche Ereignisse App/Plugin, Manifest nach Connector-Vertrag v1, Mapping |
| `tests/Unit/WorkflowConnectorPluginTest` | Plugin gegen WordPress-/Workflow-Captain-Ersatz: Eingang, Replay, Doppel, Lizenz fail-closed, Freigabe, Rotation, Aktionen, Handshake, Testereignis |

| `tests/workflow-e2e/run.sh` | Ende zu Ende über das Netz: laufende App (PostgreSQL 16 mit beiden Rollen, RLS) ↔ echtes WordPress mit Workflow Captain, Workflow Captain Pro und dem Plugin (siehe unten) |

Die Feature-Tests laufen gegen PostgreSQL mit beiden Rollen (wie in der CI, RLS aktiv).

**Workflow-E2E (`tests/workflow-e2e/run.sh`).** Lauf vom 16.11.2026: App auf `php -S` (PostgreSQL 16 aus dem
Ubuntu-Paket, eigene Datenbank, migriert und geseedet mit der Owner-Rolle), WordPress 6.9.11 mit WooCommerce 9.9.5,
Workflow Captain 0.2.0 und Workflow Captain Pro 0.1.0, Plugin 0.2.0: **46 Prüfungen bestanden**. Geprüft: F-18a (ohne
Pro 503 an die App und Wiederholung, Pro ohne Recht `runtime_not_allowed`, Statusseite und Hinweis), Anmeldung und
Freigabe, Workflows im echten Workflow Captain, Testereignis, Statusseite (Version, Laufzeit, Mapping, Zustellung,
Wiederholung, keine Geheimnisse), Ereignisse aus der Commerce-API (Anmeldung, Verknüpfung, Lieferung,
Teil-Erstattung) über das Netz zugestellt und erfasst, keine Codes/Adressen/Namen/USt-ID/Verknüpfungscode in
Workflow Captain, Workflow-Lauf → `school_admin.notice` an die App (Empfänger unter RLS der Schule), Timeout nach
erfolgreichem Aufruf (Doppel), falsche Signatur bis `failed` und Outbox-Replay, zweites Schlüsselpaar, abgelaufener
Zeitstempel, wiederholte Nonce, Diagnose. Ein Must-use-Plugin (`wp/bsw-e2e-site.php`) steht für die Lizenz des
Plugins und das Recht von Pro ein (keine Lizenzbibliothek im Repository) und wird am Ende wieder entfernt.
**Gefunden und behoben:** `ActionRegistry` las die Einstellungen der Befehle mit der Punkt-Schreibweise von `config()`
(`workflow.actions.school_admin.notice`); weil der Befehlsname selbst einen Punkt hat, fand sie die Einstellung aus
`config/workflow.php` nie, und jeder Befehl war in echter Umgebung `ACTION_DISABLED`. Die Feature-Tests hatten das
überdeckt, weil sie die Einstellung verschachtelt setzten; neuer Test mit der ausgelieferten Konfiguration.

**Nicht geprüft:** die echte Lizenzbibliothek und der Lizenzserver (Plugin und Pro), WordPress 7.x und WooCommerce
11.x (in der Sitzung nur 6.9.11/9.9.5 ohne Download), Statusseite im Browser (Tastatur, Screenreader), Last und
Nebenläufigkeit mehrerer Scheduler, TLS zwischen App und Shop.

## Betrieb

1. App: Migration (`workflow_event`, `workflow_nonce`, `workflow_command`), dann `.env`: `WORKFLOW_ENABLED=true`,
   `WORKFLOW_CONNECTOR_URL`, `WORKFLOW_OUTBOUND_SECRET`, `WORKFLOW_SECRET` (siehe `.env.example`).
2. Workflow Captain Pro mit Lizenz (Recht `workflow.custom_connectors`) und das Plugin installieren (Paket mit
   Lizenzbibliothek), Konstanten in `wp-config.php`, Lizenz des Plugins aktivieren.
3. Workflow Captain → Eigene Connectoren: „BoatingSchool Workflow Connector“ freigeben.
4. Werkzeuge → BoatingSchool Workflow: Handshake und Testereignis.
5. Fehlgeschlagene Ereignisse: `select id, type, last_error from workflow_event where status = 'failed'`, nach Behebung
   `php artisan app:workflow-outbox --requeue=<id>`.
