# Shop-Anbindung (WooCommerce)

Bootsschulen kaufen Codes im B2B-WooCommerce-Shop. Nach der Zahlung legt die
Plattform die Codes im **Codebestand der Schule** an (Admin-Bereich → Codes).
Die Schule verteilt sie an ihre Lernenden. Die Lernenden lösen sie im Web
oder in der App ein (`POST /api/v1/coupons/redeem`).

| Teil | Ort |
|---|---|
| Commerce-API (Shop → App) | `routes/commerce.php`, `app/Http/Controllers/Commerce/`, `app/Commerce/` |
| Signaturprüfung | `app/Http/Middleware/Commerce/VerifyCommerceSignature.php`, `app/Commerce/Signature.php` |
| Ausgang App → Shop (Outbox) | `app/Commerce/Outbox.php`, Befehl `app:commerce-outbox` (jede Minute) |
| Superadmin „Shopkonten“ | `app/Http/Controllers/Superadmin/ShopAccountController.php` |
| Schuladmin „Shop verknüpfen“ | `app/Http/Controllers/Admin/ShopLinkController.php`, Tab „Codes“ |
| Datenmodell | Migration `2024_02_01_000610_create_commerce_tables` |
| WooCommerce-Plugin | `integrations/woocommerce-connector/` |
| Konfiguration | `config/commerce.php`, `.env` (`COMMERCE_*`) |
| Tests | `tests/Feature/Commerce/`, `tests/Unit/ConnectorSignatureCompatibilityTest.php` |

## Entscheidungen

| Thema | Entscheidung | Grund |
|---|---|---|
| Zustellung | **Nur** in den Codebestand der Schule auf der Plattform. Kein Download, keine Mail mit Codes, kein ZIP und kein Ersatzweg | Codewerte verlassen die Plattform nie. Sie stehen weder im Shop noch in Mails, Protokollen oder Ereignissen |
| Kaufbar | Kurscodes, Verlängerungscodes, Produktcodes, Kurscodes mit Zusatzprodukten | Entspricht den Codearten der Plattform |
| Registrierung im Shop | Legt sofort ein App-Konto an (Status „eingeladen“). Die Zuordnung zu einer Schule und der Einkauf folgen erst nach Prüfung durch den Superadmin | B2B: Nur echte Bootsschulen kaufen |
| Verknüpfung | Per Einmalcode, den Inhaber oder Admin einer Schule in der App erzeugen (8 Zeichen, 15 Minuten gültig) | **E-Mail-Gleichheit verknüpft nie**, weder bei der Registrierung noch bei der Anlage aus der App |
| Führend | Die App führt Schule, Schulname und App-Konten. WooCommerce führt Rechnungsdaten, USt-IdNr. und Zahlung | Der Shop-Abgleich schreibt nur in das Shopkonto (`commerce_shop_account`), nie in das App-Konto |
| Idempotenz | Genau ein Code-Paket (`coupon_batch`) je Bestellposition, eindeutig über `(shop_id, order_id, order_line_id)` | Wiederholungen nach einer Zeitüberschreitung erzeugen nie neue Codes. Abweichender Inhalt ergibt 409 `ORDER_LINE_CONFLICT` |
| Rückerstattung/Storno | Sperrt nur **unbenutzte** Codes. Eingelöste Codes bleiben gültig | Lernende verlieren keinen Zugang, den sie schon haben |
| Alarme | Mail an `COMMERCE_ALERT_EMAIL`, sonst an die Support-Adresse der Plattform. Im Plugin an die Alarm-Adresse des Shops | |

## Abläufe

### 1. Bestehende Schule kauft im Shop

1. Die Kundin registriert sich im Shop. Das Plugin sendet `POST /accounts`.
   - Gibt es noch kein App-Konto mit der Adresse: Status `pending_review`.
   - Gibt es schon eines: Status `awaiting_link`. Es wird **nicht** verknüpft.
2. Inhaber oder Admin der Schule erzeugt unter Admin → Codes → „Shop verknüpfen“ einen Verknüpfungscode.
3. Im Shop unter „Mein Konto → Bootsschule verknüpfen“ den Code eingeben. Das Plugin sendet `POST /accounts/{id}/link`, der Status wird `linked`.
4. Nach falschen Codes: höchstens 5 Versuche in 10 Minuten (danach 429).

### 2. Neue Schule registriert sich im Shop

1. Registrierung wie oben, Status `pending_review`.
2. Der Superadmin prüft unter „Shopkonten“ und hat drei Möglichkeiten:
   - **Neue Schule** anlegen: Die Person wird Inhaberin und erhält den Link zum Einrichten des Passworts.
   - **Bestehender Schule** als Admin zuordnen.
   - **Ablehnen**: Danach ist auch keine Verknüpfung per Code mehr möglich (409 `ACCOUNT_REJECTED`).

### 3. Superadmin legt eine Schule in der App an

1. Ist `COMMERCE_SHOP_URL` gesetzt, wird ein Ereignis `tenant.provision` vorgemerkt und direkt nach der Antwort gesendet.
2. Die App ruft `POST {shop}/wp-json/boatingschool/v1/provision` auf (signiert, ohne Passwort).
3. Das Plugin antwortet je nach Lage:
   - **Neues Kundenkonto** (`matched_by=created`): Der Shop schickt den Link zum Setzen des Passworts.
   - **Für dieselbe App-Person schon angelegt** (`matched_by=app_user`): Das Konto wird wiederverwendet.
   - **Fremdes Konto mit derselben E-Mail** (`matched_by=email`): Es wird **nicht** übernommen. Der Status ist dann `needs_link`, die Verknüpfung läuft per Code.
4. Fehler: drei Versuche (nach 1, 5 und 30 Minuten), dann „fehlgeschlagen“. Es folgen eine Alarm-Mail und der Knopf „Erneut versuchen“ unter „Shopkonten“.

### 4. Bestellung

1. Kaufen dürfen nur angemeldete, verknüpfte Konten einer aktiven Schule.
   - Das prüft das Plugin beim Hinzufügen zum Warenkorb, im Warenkorb und unmittelbar vor der Bestellung (dort ohne Cache).
   - Ist die Plattform nicht erreichbar, wird gesperrt.
2. Nach der Zahlung (`processing`/`completed`) plant das Plugin je Position eine Zustellung ein (Action Scheduler). Der Lieferauftrag wird dabei eingefroren: Spätere Änderungen am Produkt ändern ihn nicht.
3. Zustellung mit `POST /orders/lines`:
   - Ab dem zweiten Versuch fragt das Plugin zuerst `GET /orders/{order}/lines/{line}` ab.
   - Ergebnis `2xx`: zugestellt, mit Bestellnotiz.
   - Keine Antwort, 408, 429 oder 5xx: neuer Versuch nach 1 bzw. 5 Minuten, höchstens drei Versuche.
   - Sonstiges `4xx` (z. B. Konto nicht verknüpft, Kurs abgeschaltet): sofort „fehlgeschlagen“.
   - Bei „fehlgeschlagen“: Bestellnotiz, Alarm-Mail und Knopf „Erneut zustellen“ in der Bestellung. Dieselbe Referenz liefert höchstens einmal.
4. Die Codes erscheinen bei der Schule unter Admin → Codes. Die Tabelle „Bestellungen aus dem Shop“ zeigt Menge, eingelöste Codes und Status.

### 5. Rückerstattung und Storno

- **Teil-Rückerstattung**: `POST /batches/{id}/void` mit `quantity`. Gesperrt werden die zuletzt erzeugten, unbenutzten Codes. Der Idempotency-Key ist `refund-{refund_id}-{item_id}`.
- **Storno oder vollständige Erstattung**: alle restlichen unbenutzten Codes. Der Idempotency-Key ist `cancel-{order_id}-{item_id}`.
- Eine noch offene Zustellung wird abgebrochen.

## Commerce-API v1

Basis `/api/commerce/v1`. Alle Aufrufe sind signiert, Fehler kommen als
`application/problem+json` mit `code`.

**Signatur:** Signiert wird der folgende Text mit HMAC-SHA256:

```
METHODE \n /PFAD \n ZEITSTEMPEL \n NONCE \n sha256(BODY)
```

| Header | Inhalt |
|---|---|
| `X-BS-Key` | Schlüssel-ID |
| `X-BS-Timestamp` | Unix-Sekunden, höchstens ±300 s Abweichung |
| `X-BS-Nonce` | Einmalwert. Wird er wiederholt, gibt es 401 `SIGNATURE_REPLAYED` |
| `X-BS-Signature` | Hex-Wert der Signatur |

Fehlercodes der Signaturprüfung: `SIGNATURE_INVALID`, `SIGNATURE_EXPIRED`, `SIGNATURE_REPLAYED`.

| Methode und Pfad | Zweck | Wichtige Fehler |
|---|---|---|
| `GET /catalog` | Kurse (`code`, `name`) und Produkte für die Produktzuordnung | |
| `POST /accounts` | Registrierung (`shop_customer_id`, `email`, Name, `company_name`, `billing_country`, `vat_id`). 201 neu, 200 Wiederholung | 422 |
| `GET /accounts/{customerId}` | Status, `can_purchase`, Schule | 404 |
| `PATCH /accounts/{customerId}` | Kontaktdaten aus dem Shop | 404 |
| `POST /accounts/{customerId}/link` | `link_code` | 422 `LINK_CODE_INVALID`, 409 `ACCOUNT_REJECTED`, 429 |
| `POST /accounts/{customerId}/deactivate` | Shopkunde gelöscht: keine Einkäufe mehr | |
| `POST /orders/lines` | Lieferauftrag (`shop_customer_id`, `order_id`, `order_line_id`, `order_number`, `kind` = course, renewal oder product, `course_code` bzw. `product_code`, `option_product_codes`, `quantity` ≤ 500, `delivery=platform`). 201 neu, 200 Wiederholung | 403 `ACCOUNT_NOT_LINKED`, 422 `UNKNOWN_COURSE`, `COURSE_NOT_AVAILABLE`, `UNKNOWN_PRODUCT`, 409 `ORDER_LINE_CONFLICT` |
| `GET /orders/{orderId}/lines/{lineId}` | Status einer Position | 404 `BATCH_NOT_FOUND` |
| `GET /batches/{id}` | Status eines Pakets | 404 `BATCH_NOT_FOUND` |
| `POST /batches/{id}/void` | `reason`, optional `quantity`. Header `Idempotency-Key` ist Pflicht | 422 |

Antworten zu einem Paket enthalten nur Zahlen, **nie Codewerte**:
`batch_id`, `status` (delivered, partially_voided oder voided),
`delivery: "platform"`, `quantity`, `redeemed`, `voided` und `open`.

## Einrichtung

**Plattform (`.env`):**

```
COMMERCE_SHOP_KEY_ID=shop
COMMERCE_SHOP_SECRET=<zufällig, mind. 32 Zeichen>   # ohne: Commerce-API aus (401)
COMMERCE_SHOP_URL=https://shop.example.de           # ohne: kein Ausgang App -> Shop
COMMERCE_OUTBOUND_KEY_ID=app
COMMERCE_OUTBOUND_SECRET=<zufällig, mind. 32 Zeichen>
COMMERCE_ALERT_EMAIL=technik@example.de             # optional
# Schlüsselwechsel: vorübergehend zusätzlich COMMERCE_SHOP_KEY_ID_NEXT / COMMERCE_SHOP_SECRET_NEXT
```

Der Scheduler (`schedule:run` jede Minute) muss laufen, er führt
`app:commerce-outbox` aus.

**Shop:** Die Installation des Plugins beschreibt
`integrations/woocommerce-connector/readme.txt`. Die Geheimnisse gehören über
`BS_CONNECTOR_*`-Konstanten in die `wp-config.php`:

| Plugin | Plattform |
|---|---|
| `BS_CONNECTOR_SECRET` | `COMMERCE_SHOP_SECRET` |
| `BS_CONNECTOR_INBOUND_SECRET` | `COMMERCE_OUTBOUND_SECRET` |

Voraussetzungen: WooCommerce ab 8 (HPOS-kompatibel) und der mitgelieferte
Action Scheduler.

**Test:** Das Plugin wurde nicht gegen ein echtes WordPress getestet. Geprüft
sind:

- Signatur und Zustellentscheidungen gegen die App (Unit-Test).
- Alle App-Endpunkte mit simulierten Shop-Aufrufen (Feature-Tests).
- Der Ausgang mit einem simulierten Shop.

Vor dem Livebetrieb sollten die Abläufe 1–5 einmal auf einer Staging-Installation
durchgespielt werden.
