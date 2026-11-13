# Integrationstest mit echtem WordPress und WooCommerce

Einmaliger Durchlauf der Abläufe 1–5 aus [README.md](README.md) mit einem
echten WordPress, WooCommerce, dem Connector-Plugin
(`integrations/woocommerce-connector/`, Version 1.2.0) und der App, alles
lokal in einem Container (13.11.2026). Kein automatisierter Test: Er
dokumentiert, was gelaufen ist, und lässt sich mit den Schritten unten
wiederholen.

## Versionen

| Teil | Version |
|---|---|
| WordPress | 7.1.4 (deutsche Sprachdatei nicht verfügbar, Oberfläche teils englisch) |
| WooCommerce | 11.3.0, HPOS-Bestelltabellen aktiv (ohne Kompatibilitätsmodus), Block-Warenkorb und Block-Checkout als Standard |
| Theme | Twenty Twenty-Five 1.5 |
| PHP | 8.3.6 (CLI-Server `php -S`, 4 Worker) |
| MariaDB | 10.11.14 (WordPress-Datenbank) |
| PostgreSQL | 16.15 (App, eigene Wegwerf-Datenbank, migriert und mit `db:seed` befüllt) |
| Laravel | 12.70.1 |
| WP-CLI | 2.12.0 |
| Playwright / Chromium | 1.56.1 / 141 |

## Aufbau

| Dienst | Adresse | Inhalt |
|---|---|---|
| WordPress | `http://127.0.0.1:8781` | `php -S … -t wp router.php` (Router für hübsche Permalinks), Permalinks `/%postname%/`, `DISABLE_WP_CRON` (Action Scheduler per `wp action-scheduler run`) |
| App | `http://localhost:8782` | `php -S` mit `server.php` aus `public/`, `COMMERCE_SHOP_SECRET`/`COMMERCE_OUTBOUND_SECRET` (je 48 Zeichen), `COMMERCE_SHOP_URL=http://127.0.0.1:8781`, `MAIL_MAILER=log`, `QUEUE_CONNECTION=sync` |
| VIES-Attrappe | `http://127.0.0.1:8783` | Kleines PHP-Skript mit dem Antwortformat der VIES-REST-API (`valid`, `requestIdentifier`, bei Bedarf HTTP 500 mit `errorWrappers: [{error: MS_UNAVAILABLE}]`) |

Konfiguration des Plugins ausschließlich über `wp-config.php`:
`BS_CONNECTOR_APP_URL`, `BS_CONNECTOR_KEY_ID`, `BS_CONNECTOR_SECRET`,
`BS_CONNECTOR_INBOUND_KEY_ID`, `BS_CONNECTOR_INBOUND_SECRET`. Das Plugin war
per Symlink aus dem Repository eingebunden.

Testgerüst als Must-Use-Plugin (nur im Test):

- `pre_wp_mail` schreibt alle Mails als JSON in einen Ordner (es gibt keinen
  Mailserver); daraus kommt der Bestätigungslink.
- `bs_connector_vies_url` zeigt auf die VIES-Attrappe (der echte Dienst
  `ec.europa.eu` war aus dem Container nicht erreichbar, siehe Grenzen).
- `woocommerce_billing_fields` ergänzt ein Feld `billing_vat_id`, wie es ein
  USt-Plugin anlegen würde (für Rechnungsadresse und klassischen Checkout).

WooCommerce-Einstellungen: Land DE, Währung EUR, Registrierung unter „Mein
Konto“ an, Gastbestellung aus, „Coming soon“ aus, Zahlarten Überweisung
(`bacs`), Scheck (`cheque`) und Nachnahme (`cod`) aktiv. Plugin-Einstellungen:
Alarm-Adresse, „Eigene USt-IdNr. (für VIES)“ = DE811191002.

App: Superadmin per Tinker angelegt (`is_superadmin`, bestätigte Adresse, ohne
2FA, weil `APP_ENV=local`); Schuladmin `admin@bootsschule-mueller.de` aus den
Seedern.

Gesteuert wurde mit Playwright (Browser, Formulare wie eine Kundin bzw. ein
Admin) und WP-CLI (`wp eval-file` für Bestellungen, `wp wc shop_order update`
für Statuswechsel, `wp action-scheduler run` für die Hintergrundaufgaben).
Geprüft wurde jeweils im Shop (Bestellnotizen, Positions-Metadaten,
Nutzer-Metadaten, Protokoll `bs-connector`) und in der App-Datenbank
(`commerce_shop_account`, `coupon_batch`, `coupon`).

## Abläufe und Ergebnisse

| # | Ablauf | Ergebnis |
|---|---|---|
| 1 | Registrierung unter „Mein Konto“ (Name der Schule, Land, USt-IdNr.) → Bestätigungsmail → Link geöffnet (ohne Anmeldung, zweites Öffnen: „bereits bestätigt“) → Action Scheduler → `POST /accounts` | bestanden: Shopkonto in der App `pending_review`, USt-IdNr. normalisiert (`DE811191002`) |
| 1a | Superadmin gibt in der App frei („Neue Schule“, Browser) | bestanden: Status `linked`, neue Schule; im Shop „Verknüpft mit: …“ |
| 1b | Zweite Kundin: Schuladmin erzeugt in der App einen Verknüpfungscode, Kundin gibt erst einen falschen, dann den richtigen Code ein | bestanden: falscher Code → „ungültig oder abgelaufen“ (422 `LINK_CODE_INVALID`), richtiger → „verknüpft mit Bootsschule Müller“ |
| 1c | Konto mit unbestätigter Adresse legt Bootsschul-Code in den Warenkorb | bestanden: abgelehnt |
| 2 | Produkt im Backend angelegt (Art Kurscode, Kurs SRC aus dem Katalog der App, Browser) | bestanden: Zuordnung gespeichert, Produkt automatisch virtuell |
| 2a | Kauf im Block-Checkout (Überweisung, Bestellung „In Wartestellung“) → Admin setzt „Abgeschlossen“ → Action Scheduler | bestanden: 9 Codes SRC im Codebestand der Schule, Bestellnotiz, Box „Bootsschul-Codes“ im HPOS-Bestellbildschirm |
| 2b | Produktcode (Fahrstunde `FAHRSTUNDE-1-EH`), Menge 2 | bestanden: Paket `product` mit 2 Codes |
| 2c | Nachnahme: Bestellung „In Bearbeitung“ | bestanden: keine Zustellung, einmalige Notiz; nach „Abgeschlossen“ zugestellt. Im Checkout war Nachnahme bei Bootsschul-Codes ausgeblendet |
| 4a | Kontingent: App mit `COMMERCE_DAILY_CODE_LIMIT=20`, Bestellung über 10 Codes | bestanden: 429 `CODE_QUOTA_EXCEEDED` → Position „fehlgeschlagen“ ohne automatische Wiederholung, Notiz, Alarm-Mail mit Hinweis auf die Freigabe; nach Anheben des Limits über WooCommerce → Bootsschul-Zustellungen „Erneut zustellen“ (Sammelaktion) zugestellt |
| 4b | „In Wartestellung“ vor der Zustellung | bestanden: Position `deferred` (im Backend „zurückgestellt“), nach „In Bearbeitung“ mit demselben Auftrag zugestellt; „In Wartestellung“ nach der Zustellung sperrt nichts |
| 5a | Teilrückerstattung mit Menge 2 von 9 | bestanden: Paket `partially_voided`, 7 offen, 2 gesperrt, Notiz |
| 5b | Storno nach Zustellung (4 Codes) | bestanden: Paket `voided`, alle 4 gesperrt |
| 5c | Storno vor der Zustellung bzw. einer zurückgestellten Position | bestanden: kein Paket in der App, Position „abgebrochen“ |
| 3 | Superadmin legt in der App eine Schule an (Browser) | bestanden: Kundenkonto im Shop (Rolle `customer`, als bestätigt markiert), Shopkonto `provisioning_status = done` |
| 3a | Dasselbe mit der Adresse eines bestehenden, fremden Shopkontos | bestanden: `matched_by = email`, Konto nicht übernommen, App `needs_link` |
| 3b | Signierte Provisionierungsanfrage zweimal gesendet, dazu eine mit falscher Signatur | bestanden: Wiederholung und falsche Signatur 401 |
| – | Kundin im Shop gelöscht | bestanden: `POST /accounts/{id}/deactivate`, Shopkonto in der App `revoked` |
| – | `wp bs-connector reconcile --days=1` | bestanden: keine Abweichungen |
| – | „Verbindung testen“ auf dem Einstellungs-Tab | bestanden: „14 Kurse und 4 Produkte im Katalog“ |

### USt-IdNr.-Prüfung

| Fall | Ergebnis |
|---|---|
| Registrierung mit `DE12345` | Fehlermeldung „nicht das gültige Format für DE“, kein Konto |
| Registrierung mit `DE811191003` (laut VIES ungültig) | Fehlermeldung „laut EU-Prüfdienst (VIES) nicht gültig“ |
| Registrierung mit `DE 811 191 002` | angenommen; am Kunden `valid`, Zeitpunkt, Abfragenummer (`requestIdentifier`); nur eine VIES-Anfrage je Nummer und Seitenaufruf |
| Registrierung mit `CHE-123.456.789`, Land CH | angenommen, „nicht geprüft (kein EU-Land)“ |
| VIES nicht erreichbar (`MS_UNAVAILABLE`) bei Registrierung und Block-Checkout | angenommen, „nicht geprüft“, Wiederholung nach 15 Minuten eingeplant, Bestellnotiz „nicht geprüft … wird automatisch erneut geprüft“; nach Ausführen der Wiederholung (VIES wieder erreichbar): Kunde `valid` und zweite Bestellnotiz mit Abfragenummer |
| Wiederholungen erschöpft | Alarm-Mail „USt-IdNr. konnte nicht geprüft werden“ |
| Benutzerprofil im Backend | Abschnitt „USt-IdNr.-Prüfung (VIES)“ mit Nummer, Ergebnis, Zeitpunkt, Abfragenummer, Versuchen; „Jetzt erneut prüfen“ funktioniert |
| „Mein Konto → Adressen“: `NL123456789A01`, `DE811191003`, dann `DE811191002` | erste zwei abgelehnt (Fehlermeldung, nicht gespeichert), dritte gespeichert und geprüft |
| Klassischer Checkout (Shortcode): `FR12345`, `DE811191003`, dann `ATU12345678` | erste zwei mit Fehlermeldung abgelehnt, dritte bestellt, Notiz mit Abfragenummer, Kunde übernimmt die neue Nummer |
| Echter VIES-Dienst ohne Attrappe | aus dem Container nicht erreichbar → „nicht geprüft“ (`NO_RESPONSE`), Registrierung nicht blockiert |

### Geheimnisse in wp-config.php

| Fall | Ergebnis |
|---|---|
| Konstanten gesetzt, nichts in der Datenbank | Einstellungsseite ohne Geheimnis-Felder, kein Hinweis, Verbindungstest erfolgreich |
| Konstanten gesetzt, alte Kopie in der Datenbank | dauerhafter Hinweis „werden nicht verwendet … einmal speichern“; nach dem Speichern aus der Datenbank entfernt, Hinweis weg |
| Nur Datenbank, Standard (erlaubt) | Anbindung funktioniert, Hinweis mit Empfehlung, Felder sichtbar |
| Nur Datenbank, Filter `bs_connector_allow_db_secrets` = false bzw. Einstellung aus | keine Aufrufe („Plugin nicht konfiguriert“), Hinweis „werden ignoriert“ |

## Gefundene und behobene Fehler

Im Plugin:

1. **USt-IdNr. mit Kennung eines Nicht-EU-Landes** (z. B. `CHE-123.456.789`
   bei Rechnungsland CH) wurde als `CHCHE123456789` gespeichert. Jetzt wird
   die Kennung des Rechnungslands erkannt (`BS_Vat::normalize`, Unit-Test).
2. **USt-IdNr. an die App** ging so, wie die Kundin sie getippt hatte
   (`DE 811 191 002`). Jetzt normalisiert (`DE811191002`).
3. **Box „Bootsschul-Codes“** in der Bestellung zeigte für zurückgestellte
   Positionen den internen Wert `deferred`. Jetzt „zurückgestellt
   (Bestellung derzeit nicht bezahlt)“.

In der App war für den Shop-Vertrag nichts zu ändern.

## Grenzen

- **VIES:** `ec.europa.eu` war aus dem Container gesperrt; getestet wurde
  gegen eine Attrappe mit dem dokumentierten Antwortformat. Das echte
  Verhalten (u. a. ob `MS_UNAVAILABLE` als HTTP 200 mit `userError` oder als
  HTTP 500 mit `errorWrappers` kommt) deckt das Plugin für beide Formen ab,
  gesehen wurde es nicht. Ebenso wenig die Abfragenummer eines echten
  Requesters.
- **Block-Checkout und USt-IdNr.:** WooCommerce hat dort kein USt-IdNr.-Feld;
  geprüft wurde der Block-Checkout nur mit dem am Kunden gespeicherten
  Ergebnis (Bestellnotiz). Eine Nummer, die ein anderes Plugin als
  Bestell-Meta ablegt, wurde dort nicht getestet.
- **Zahlungen:** nur Offline-Zahlarten (Überweisung, Scheck, Nachnahme), kein
  echtes Gateway mit `payment_complete()`-Rückmeldung.
- **Rückerstattungen** über `wc_create_refund()` (derselbe Hook wie der Knopf
  im Backend), nicht über die Backend-Oberfläche.
- **E-Mails** wurden abgefangen, nicht zugestellt; deutsche Sprachdateien für
  WordPress/WooCommerce waren nicht ladbar (wordpress.org gesperrt).
  WordPress und WooCommerce kamen deshalb aus GitHub (Spiegel
  `WordPress/WordPress`, Release-Paket `woocommerce.zip`).
- **Zeitgesteuerte Wiederholungen** (Zustellung nach 1/5/30 Minuten,
  USt-IdNr. nach 15 Minuten …) wurden nicht abgewartet, sondern die geplante
  Aktion gezielt ausgeführt (`wp action-scheduler action run <id>`).
- Kein Multisite, kein Caching-Plugin, kein Webserver außer `php -S`.

## Wiederholen

1. MariaDB starten, Datenbank und Benutzer für WordPress anlegen; WordPress
   (`git clone --depth 1 --branch 7.1.4 https://github.com/WordPress/WordPress`)
   und WooCommerce (`woocommerce.zip` aus den GitHub-Releases) bereitstellen,
   `wp config create`, `wp core install`, `wp plugin activate woocommerce`.
2. Plugin nach `wp-content/plugins/woocommerce-connector` verlinken,
   `BS_CONNECTOR_*`-Konstanten setzen, `wp rewrite structure '/%postname%/'`,
   Plugin aktivieren.
3. App-Datenbank anlegen (`createdb`, `GRANT CONNECT … TO boatingschool_app`),
   `php artisan migrate --database=pgsql_admin --force` und `db:seed`, App mit
   den `COMMERCE_*`-Variablen starten.
4. Abläufe wie oben; nach jedem Schritt, der etwas einplant,
   `wp action-scheduler run`.
5. Aufräumen: Server beenden, App-Datenbank löschen, MariaDB stoppen.
