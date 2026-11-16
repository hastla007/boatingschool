# BoatingSchool Form-Connector (WordPress)

Übergibt Einsendungen ausgewählter Form-Captain-Formulare als **Lead** an die Lead-Inbox der Plattform
(`POST /api/integrations/v1/form-captain/leads`, Vertrag [`docs/integrations/form-captain-leads.md`](../../docs/integrations/form-captain-leads.md)).
Connector nach dem Vertrag v1 von Form Captain (`docs/CONNECTORS.md` in `hastla007/wp-formmaster`, Vorbild:
`connectors/form-captain-reference-connector/`).

**Produkt (Entscheidung 17, endgültig 16.11.2026):** eigenes, lizenziertes Projektprodukt, **nicht** Teil von
Form Captain Pro. Produktschlüssel, Ordner und Textdomain `boatingschool-form-connector`, Präfix `boatingschool_form`,
Recht `boatingschool_form.leads`, Katalogdatei `wplc-catalog.json` (Status `planned`: nicht im Verkauf, Freigabe des
Inhabers für den Pilot steht aus).

## Was der Connector tut

- Meldet sich über `form_captain_connectors_v1` an (Key `boatingschool_form`, Contract `1.0`), erst nach
  `form_captain_loaded_v1` und nur bei Major 1. Ohne Form Captain: still.
- Aktion `boatingschool_form.send_lead` mit
  - Einstellungen je Formular: `purpose` (Demo, Beratung, Infos zum Schulzugang), `consent_text_revision` (Pflicht),
    `consent_text_hash` (optional, SHA-256 des Einwilligungstexts), `page_url` (Seite des Formulars);
  - Mapping, nur diese Felder, keines sensibel: `email` (Pflicht), `consent` (Pflicht, Feldtyp Einwilligung oder
    Checkbox), `name`, `phone`, `organisation`, `role`, `school_size`, `message`.
- Übertragen werden nur die gemappten Werte, Formular- und Entry-ID, die Website (`home_url`), die Seite des Formulars
  und der Verweis auf die Einwilligung (Einsendezeitpunkt, Revision, optional Hash). Nie: IP, User-Agent, versteckte
  oder sensible Felder, andere Formularfelder, der Einwilligungstext.
- **Quell-URL-Allowlist:** Website und `page_url` müssen auf einem erlaubten Host liegen (Standard: Host der eigenen
  Website; weitere über `BOATINGSCHOOL_FORM_ALLOWED_HOSTS`). Sonst `fail source_url_not_allowed`, nichts wird gesendet.
- **Idempotenz:** `Idempotency-Key` = Schlüssel des Form-Captain-Jobs (Website, Entry, Aktion, Instanz). Der Body ist je
  Job deterministisch (Zeitpunkte aus dem Entry). Eine Wiederholung – Timeout nach erfolgreichem Schreiben, Neustart des
  Workers, „Wiederholen“ in Form Captain – liefert dieselbe `lead_id`, nie einen zweiten Lead.
- **Testlauf** (`test_mode`): nur `POST /ping` (Schlüssel und Erreichbarkeit), kein Lead.
- **Löschung:** `form_captain_entry_deleted_v1` und `form_captain_consent_withdrawn_v1` → signiertes
  `DELETE /leads/{lead_id}` (abschaltbar, Standard an). Ist die Plattform nicht erreichbar, wird stündlich wiederholt
  (höchstens 48-mal). Läuft auch ohne gültige Lizenz, aber nur mit `lib/`.

## Zustellstatus (BS-12)

Den Zustellstatus führt die **Outbox von Form Captain** (*Integrationen → Integrations-Jobs*): Versuche, letzter
Fehlercode, `remote_id` (= `lead_id`), Dead-letter, manuelles „Wiederholen“. Der Connector liefert:

| Plattform | Ergebnis | Code |
|---|---|---|
| 201 / 200 / 202 | `success` mit `remote_id` | `created` / `already_created` / `accepted_merged` |
| 401 `signature_expired` | erster Versuch `retry`, danach `fail` | `signature_expired` |
| 401 sonst, 403, 400, 404 | `fail` | Code der Plattform |
| 409 `replay_detected` | `retry` (neue Nonce) | `replay_detected` |
| 409 `idempotency_conflict`, 422 | `fail` | `idempotency_conflict`, `schema_invalid` |
| 429, 5xx | `retry` | `rate_limited`, `remote_unavailable` |
| Timeout / Netz | `retry` | `timeout`, `remote_unavailable` |
| nicht eingerichtet | `retry` | `not_configured` |
| Mapping/Einstellung fehlt | `fail` (nach Korrektur manuell wiederholen) | `invalid_email`, `consent_missing`, `consent_revision_missing`, `source_url_not_allowed`, … |

Zusätzlich merkt sich der Connector je Entry die `lead_id` (Option `boatingschool_form_ledger`, autoload no, nur IDs)
für Löschungen.

## Einrichtung (standardmäßig aus)

1. Plattform: `FORM_CAPTAIN_LEAD_KEY_ID` und `FORM_CAPTAIN_LEAD_SECRET` setzen (siehe Vertrag, Abschnitt 3).
2. WordPress, in `wp-config.php` (empfohlen; sonst *Form Captain → BoatingSchool-Leads*, Geheimnis write-only):

   ```php
   define('BOATINGSCHOOL_FORM_ENDPOINT', 'https://app.boatingschool.example'); // nur HTTPS
   define('BOATINGSCHOOL_FORM_KEY_ID', 'fc-lead-2026-11');
   define('BOATINGSCHOOL_FORM_SECRET', '…');
   define('BOATINGSCHOOL_FORM_ALLOWED_HOSTS', 'www.boatingschool.example');   // optional
   define('BOATINGSCHOOL_FORM_REMOTE_DELETE', true);                          // optional, Standard an
   ```

3. Lizenzschlüssel auf der Lizenzseite des Kernels unter dem Menü von Form Captain eintragen.
4. *Form Captain → Integrationen*: Connector „BoatingSchool-Leads“ aktivieren (Opt-in von Form Captain), „Verbindung
   testen“.
5. Im Formular die Aktion „Als Lead an BoatingSchool senden“ hinzufügen, Felder zuordnen, Revision des
   Einwilligungstexts eintragen.

## Lizenz und Bau (BS-11)

Dieses Repository ist **öffentlich** (BS-03). Add-on-Kernel und Lizenzclient sind proprietärer Code aus dem privaten
Repository `captain-pro` und werden **nur beim Bau** eingebunden:

```sh
php bin/build-libs.php --captain-pro=/pfad/zu/captain-pro          # schreibt lib/ (in .gitignore)
php bin/build-libs.php --captain-pro=/pfad/zu/captain-pro --check  # prüft lib/ gegen captain-pro
php bin/build-zip.php                                              # build/boatingschool-form-connector-<version>.zip
```

- `bin/build-libs.php` liest aus einem sauberen captain-pro-Checkout `packages/addon-kernel/src` und
  `packages/license-client` und schreibt sie mit `build/lib/ClientExporter.php` von captain-pro unter den Namespaces
  `BoatingschoolFormConnector\Kernel` bzw. `\Licensing` und der Textdomain des Plugins nach `lib/`. `lib/SOURCE.md`
  nennt Commit, Versionen und SHA-256 je Datei. Kein Netz, kein Composer.
- `bin/build-zip.php` bricht ab, wenn `lib/` fehlt oder von `lib/SOURCE.md` abweicht.
- **Fail-closed:** Ohne `lib/` (z. B. der Ordner direkt aus dem Repository) meldet sich der Connector nicht bei Form
  Captain an und zeigt im Admin „Der Ordner lib fehlt“. Mit `lib/`, aber ohne gültige Lizenz mit dem Recht
  `boatingschool_form.leads` ebenso; offene Jobs bleiben in Form Captain pausiert („Connector fehlt“) und laufen weiter,
  sobald die Lizenz gilt. Löschungen bereits zugestellter Leads laufen auch ohne Lizenz (Datenschutz).
- `lib/` nie einchecken und nicht mit `pint` formatieren (sonst stimmen die Prüfsummen nicht).
- Lizenzserver-Adresse und öffentliche Schlüssel in `config.php` sind Platzhalter (`license.example.com`): solange
  meldet der Kernel „nicht eingerichtet“ und der Connector bleibt aus.

## Tests

`tests/Unit/FormConnectorContractTest.php` (Vertrag v1: Form, Ergebnisse, Idempotenz, Testmodus, Allowlist, Löschung,
fail-closed, Katalog; HMAC gegen die Plattform) und `tests/Feature/Integrations/FormConnectorEndToEndTest.php`
(vom Connector gebaute Anfrage gegen den echten Endpunkt). Beide laufen mit einem WordPress-Ersatz
(`tests/Unit/form-connector-wp-harness.php`), nicht gegen echtes WordPress. Der Test „mit lib/, ohne Lizenz“ läuft nur,
wenn `lib/` gebaut ist. Staging-Abnahme: offen (BS-13).

## Deinstallation

Entfernt `boatingschool_form_settings`, `boatingschool_form_ledger`, `boatingschool_form_pending_deletions`, das
Cron-Ereignis und (mit `lib/`) die Lizenzdaten. Leads in der Plattform bleiben (Inbox, Aufbewahrungsfrist dort).
