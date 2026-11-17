# Staging-Test der Shop-Anbindung

Das Plugin `integrations/woocommerce-connector/` wurde bisher nur gegen
simulierte Shop-Aufrufe getestet. Diese Checkliste spielt die Abläufe aus
[`README.md`](README.md) einmal gegen ein echtes WordPress/WooCommerce und
eine Staging-Instanz der Plattform durch. Einmal vor dem Livebetrieb und nach
größeren WooCommerce-Updates abhaken.

> **Automatisch abgedeckt:** Der CI-Lauf
> [`.github/workflows/connector-wordpress.yml`](../../.github/workflows/connector-wordpress.yml)
> (`tests/connector-e2e/run.sh`, WordPress 7.1.4 / WooCommerce 11.3.0 mit MySQL)
> spielt bei jeder Änderung an Plugin oder Commerce-Code einen Durchlauf durch:
> Kundenkonto mit Bestätigungsmail und `POST /accounts`, Verknüpfung per Code,
> Produkt mit Kurscode aus dem Katalog, Bestellung (Überweisung, im Backend
> abgeschlossen) bis zur Zustellung der Codes. Punkte mit **✓ automatisch (CI)**
> prüft dieser Lauf vollständig, **◐ teilweise (CI)** nennt, was davon; alles
> andere bleibt beim Staging-Test von Hand zu prüfen. Er läuft mit
> `APP_ENV=local`, Mailer `log`, ohne Cron und ohne WP-Cron (Aktionen einzeln per
> WP-CLI) und ersetzt deshalb weder den Staging-Test noch `app:check-production`.

## Vorbereitung

- [ ] Staging-Plattform mit `COMMERCE_SHOP_SECRET`, `COMMERCE_OUTBOUND_SECRET`
      (je `openssl rand -hex 32`), `COMMERCE_SHOP_URL` und `COMMERCE_ALERT_EMAIL`.
      `php artisan app:check-production` meldet bei „Geheimnisse der
      Shop-Anbindung“ `ok`.
- [ ] Cron für `schedule:run` läuft (sonst wird `app:commerce-outbox` nicht gesendet).
- [ ] WordPress ≥ 6.5, WooCommerce ≥ 8 mit HPOS, PHP ≥ 8.1, Permalinks **nicht** „Einfach“.
- [ ] Plugin kopiert und aktiviert. Geheimnisse in `wp-config.php` (siehe `readme.txt`).
      Ohne Konstanten zeigt WooCommerce → Einstellungen → Bootsschule keine Geheimnis-Felder,
      sondern die `define()`-Zeilen („Geheimnisse aus der Datenbank erlauben“ ist standardmäßig aus).
      **◐ teilweise (CI):** Plugin aktiviert, signierte Aufrufe Shop → App mit den
      Konstanten aus `wp-config.php`; die Einstellungsseite ohne Konstanten nicht.
- [ ] Update von 1.3.x mit Geheimnissen nur in der Datenbank: Anbindung läuft nach dem Update weiter,
      „Geheimnisse aus der Datenbank erlauben“ ist eingeschaltet, Administratoren sehen den Hinweis
      „beim Update … eingeschaltet“. Nach Umzug in `wp-config.php` und Ausschalten verschwindet er.
- [ ] Testzahlart mit Zahlungsbestätigung (z. B. Stripe-Testmodus) und zusätzlich „Nachnahme“ aktiv.
- [ ] Feld „Firmenname“ an der Kasse sichtbar, auch wenn es in WooCommerce ausgeblendet ist (Standard neuer Installationen; ab 1.4.4 zeigt das Plugin es dann als „optional“, „Erforderlich“ bleibt erforderlich). Klassische und Block-Kasse prüfen; kein Firmenfeld-Hinweis im Backend, auf dem Einstellungs-Tab „Bootsschule“ der Satz „Das Firmenfeld wird an der Kasse als optional angezeigt …“. Plugin deaktiviert → wieder die gespeicherte WooCommerce-Einstellung.
- [ ] Zwei Testschulen auf der Plattform: **A** (aktiv, Kurs SBF-SEE freigeschaltet), **B** (aktiv, SBF-SEE abgeschaltet).
- [ ] Shop-Produkte: Kurscode SBF-SEE, Verlängerungscode SBF-SEE, ein Produktcode, ein Kurscode mit Zusatzprodukt, ein variables Produkt.
- [ ] Prüfen: `WooCommerce → Status → Geplante Aktionen`, Gruppe `bs-connector`, ist nach den Bestelltests leer bzw. „Abgeschlossen“.

## 1. Bestehende Schule verknüpft sich

- [ ] Neues Shopkonto mit einer Adresse **ohne** App-Konto registrieren → Superadmin „Shopkonten“ zeigt `pending_review`; auf der Plattform entsteht **kein** App-Konto (Selbstregistrierung mit der Adresse bleibt möglich).
- [ ] Shopkonto mit der Adresse eines bestehenden Admins von Schule A → ebenfalls `pending_review` nach außen, Superadmin sieht `awaiting_link`, **keine** automatische Verknüpfung.
- [ ] In Schule A: Admin → Codes → „Shop verknüpfen“ → Code im Shop unter „Mein Konto → Bootsschule verknüpfen“ eingeben → Status `linked`, Schulname im Shop sichtbar. **◐ teilweise (CI):** Code eingeben → `linked` mit der richtigen Schule, „Verknüpft mit: …“ in „Mein Konto“; der Code wird per Skript erzeugt, nicht über den Knopf im Admin-Bereich.
- [ ] Fünf falsche Codes in Folge → sechster Versuch abgelehnt (429), Meldung im Shop verständlich.
- [ ] „Mein Konto → Bootsschule verknüpfen“ mehrfach neu laden → im Zugriffsprotokoll der Plattform höchstens ein `GET /accounts/{id}` je 5 Minuten (bzw. 60 s bei Fehler oder nicht freigeschaltetem Konto, ab 1.4.3).
- [ ] Als Shop-Manager (ohne Administratorrechte) angemeldet: Tab „Bootsschule“ unter WooCommerce → Einstellungen fehlt. Als Administrator mit Konstanten in `wp-config.php`: die betroffenen Felder sind nicht editierbar.
- [ ] Abgelaufener Code (> 15 Minuten) wird abgelehnt.
- [ ] Konto neu abgleichen: Firma des verknüpften Shopkontos auf der Plattform (Superadmin „Shopkonten“) abweichend setzen, im Shop nichts ändern. Als Administrator im Benutzerprofil „Konto mit der Bootsschul-Plattform neu abgleichen“ → Hinweis „Abgleich eingeplant“, Aktion `bs_connector_sync_account` mit `force` in „Geplante Aktionen“, danach zeigt die Plattform wieder die Firma aus dem Shop. Als Shop-Manager und bei einem nicht registrierten Konto fehlt der Knopf. `wp bs-connector resync <id>` meldet „abgeglichen (HTTP 200)“.

## 2. Neue Schule über den Shop

- [ ] Neues Shopkonto → Superadmin „Neue Schule anlegen“ → Person erhält Passwort-Link, Shopkonto `linked`, Einkauf spätestens nach einer Minute möglich (ab 1.4.3).
- [ ] Weiteres Shopkonto → „Bestehender Schule zuordnen“ → Inhaber von Schule A bekommt Mail.
- [ ] Weiteres Shopkonto → „Ablehnen“ → Verknüpfung per Code danach mit „abgelehnt“ verweigert.

## 3. Schule in der App anlegen (Ausgang App → Shop)

- [ ] Superadmin legt Schule C mit neuer Adresse an → innerhalb einer Minute Kundenkonto im Shop, Shop schickt Passwort-Link.
- [ ] Schule D mit der Adresse eines **fremden** Shopkontos → Shopkonto bleibt unverändert, Plattform zeigt `needs_link` und Hinweis im Codes-Tab.
- [ ] Shop vorübergehend nicht erreichbar (z. B. Plugin deaktivieren) → Wiederholungen nach 1/5/30 Minuten, dann „fehlgeschlagen“, Alarm-Mail, Knopf „Erneut versuchen“ funktioniert nach Reaktivierung.

## 4. Bestellung

- [ ] Nicht angemeldet / nicht verknüpft: „In den Warenkorb“ verweigert, auch im **Block-Checkout**.
- [ ] Schule B kann SBF-SEE nicht in den Warenkorb legen (`deliverable_course_codes`).
- [ ] Bei Bootsschul-Produkten im Warenkorb ist „Nachnahme“ ausgeblendet.
- [ ] Verknüpftes Konto von Schule A kauft je ein Produkt jeder Art, Menge 3, Testzahlung → Bestellnotizen „eingeplant“ und „zugestellt“; Schule A sieht unter Admin → Codes die Codes und die Zeile in „Bestellungen aus dem Shop“. **◐ teilweise (CI):** nur Kurscode, Menge 3, Überweisung im Backend auf „Abgeschlossen“ (keine Testzahlung, keine Kasse) → Position zugestellt, Notiz „… im Codebestand der Bootsschule hinterlegt“, ein Codepaket mit 3 offenen Codes der Schule in der Datenbank; die Admin-Ansicht nicht.
- [ ] Im Shop (Bestellung, Notizen, Mails, Logs) erscheint **kein** Codewert.
- [ ] Variables Produkt: Zustellung nach der Zuordnung am Hauptprodukt.
- [ ] Bestellung im Backend mit Zahlart Nachnahme auf „In Bearbeitung“ → **keine** Zustellung, einmalige Notiz; nach „Abgeschlossen“ wird zugestellt.
- [ ] Plattform während der Zustellung kurz nicht erreichbar → Wiederholung, am Ende genau **ein** Code-Paket (nicht doppelt).
- [ ] `COMMERCE_DAILY_CODE_LIMIT` auf 2 setzen, Menge 3 bestellen → sofort „fehlgeschlagen“, Alarm-Mail; Limit anheben, „Erneut zustellen“ → geliefert.
- [ ] Produktzuordnung nach dem Kauf ändern → bereits eingeplante Lieferung bleibt beim ursprünglichen Auftrag.
- [ ] Kontoabgleich nur bei Änderung (ab Plugin 1.4.1): verknüpftes Konto bestellt erneut, ohne an der Kasse Name, Firma, Land oder USt-IdNr. zu ändern → unter `Geplante Aktionen` (Gruppe `bs-connector`) **keine** neue Aktion `bs_connector_sync_account` und im Zugriffsprotokoll der Plattform **kein** `PATCH /accounts/{id}`.
- [ ] Dasselbe Konto ändert an der Kasse die Firma → genau **ein** `PATCH /accounts/{id}`; die neue Firma steht im Superadmin unter „Shopkonten“. Danach eine weitere Bestellung ohne Änderung → wieder kein `PATCH`.

## 5. Rückerstattung und Storno

- [ ] Teil-Rückerstattung über Positionsmenge 1 → bei der Schule 1 Code gesperrt (der zuletzt erzeugte unbenutzte).
- [ ] Einen Code einlösen, dann Bestellung stornieren → eingelöster Code bleibt gültig, alle übrigen gesperrt.
- [ ] Rückerstattung nur als Betrag (ohne Menge) → keine Sperre, aber Bestellnotiz und Alarm-Mail.
- [ ] Bestellung „In Wartestellung“ vor der Zustellung → Status `deferred`; wieder „In Bearbeitung“ → Zustellung mit unveränderter Menge.
- [ ] Stornierte Bestellung wieder auf „In Bearbeitung“ → keine Nachlieferung, Notiz und Alarm-Mail.

## Abschluss

- [ ] Plugin deaktivieren und löschen → `uninstall.php` entfernt Einstellungen, Zwischenspeicher und geplante Aktionen der Gruppe `bs-connector`; Bestellungen samt Zustellstatus bleiben.
- [ ] Ergebnis (Datum, WooCommerce-/WordPress-Version, Auffälligkeiten) in `docs/commerce/README.md` unter „Test“ nachtragen.

## Ergebnis lokaler E2E-Test (15.11.2026, WordPress 7.1.4, WooCommerce 11.3.0)

Einmal lokal im Container durchgespielt: echtes WordPress und WooCommerce mit
dem Plugin aus diesem Repository, dazu die echte App (`php artisan serve`,
PostgreSQL 16). **Kein Ersatz für den Staging-Test** – die Abweichungen von
einer echten Installation stehen unten.

**Aufbau**

- WordPress 7.1.4 (Composer-Paket `johnpbloch/wordpress-core`), WooCommerce 11.3.0
  (GitHub-Release), WP-CLI 2.11.0, PHP 8.3 eingebauter Webserver, Permalinks
  „Beitragsname“, HPOS aktiv (`wp wc hpos enable`), Theme Twenty Twenty-Five
  (Block-Warenkorb und -Kasse; klassische Kasse über eine Seite mit `[woocommerce_checkout]`).
- Datenbank: **SQLite** über den Drop-in `wp-sqlite-db` 1.3.3 (Composer
  `aaemnnosttv/wp-sqlite-db`), nicht MySQL. Das offizielle SQLite-Plugin war im
  Container nicht erreichbar. Action Scheduler lief damit nur mit einem
  Test-mu-Plugin, das drei MySQL-Konstrukte umschreibt (`FROM DUAL`,
  `UPDATE … JOIN` beim Beanspruchen, Alias `` `group` ``); geplante Aktionen
  wurden einzeln per `wp action-scheduler action run <id>` ausgeführt.
- Geheimnisse als Konstanten in `wp-config.php`, App mit `COMMERCE_SHOP_SECRET`,
  `COMMERCE_OUTBOUND_SECRET` (je 64 Hex-Zeichen), `COMMERCE_SHOP_URL`, `COMMERCE_ALERT_EMAIL`.
- Mails: WordPress per Test-mu-Plugin in eine Datei, App mit Mailer `log`.
  Ausgang der App per `php artisan app:commerce-outbox` von Hand (kein Cron);
  Wartezeiten durch Vorziehen von `available_at` übersprungen.
- Zahlarten: Scheck (Status von Hand auf „In Bearbeitung“), Banküberweisung, Nachnahme.

**Bestanden**

- Vorbereitung: Plugin aktiviert, Katalogabruf (14 Kurse, 6 Produkte, `max_quantity` 500).
  Tab „Bootsschule“ nur für Administratoren, für Shop-Manager nicht vorhanden; per
  Konstante gesetzte Felder nicht editierbar, kein Geheimnis im HTML. Produktzuordnung über
  das echte Produktformular gespeichert (Produkt wird virtuell).
- 1: Registrierung → `pending_review`, kein App-Konto; Adresse eines bestehenden Admins →
  `awaiting_link`, im Shop „noch nicht freigeschaltet“; Verknüpfung per Code → `linked`,
  Schulname im Shop; fünf falsche Codes → sechster Versuch „Zu viele Versuche“ (429);
  abgelaufener Code abgelehnt; fünfmal „Mein Konto“ neu laden → kein weiterer
  `GET /accounts/{id}`. Bestandskunde ohne Registrierung wird beim Verknüpfungsversuch nachregistriert.
- 2: „Als neue Schule freigeben“ → Inhaber, `linked`; „Bestehender Schule zuordnen“ → Admin,
  `linked`; „Ablehnen“ → `rejected`, „Mein Konto“ zeigt den Support-Hinweis ohne Formular,
  die App lehnt eine Verknüpfung mit 409 `ACCOUNT_REJECTED` ab.
- 3: Schule C → Kundenkonto im Shop (Name, Firma, `_bs_provisioned`), Mail mit Link zum
  Setzen des Passworts; Schule D mit Adresse eines fremden Shopkontos → Shopkonto
  unverändert, `needs_link`, Hinweis im Codes-Tab, nach Verknüpfung per Code erledigt;
  Plugin deaktiviert → 404, Versuche 1–4, „fehlgeschlagen“, Alarm-Mail, nach Reaktivierung
  „Erneut versuchen“ → Konto angelegt.
- 4: Gast und nicht freigeschaltete/abgelehnte Konten: „In den Warenkorb“ verweigert
  (klassisch und Store API); entknüpftes Konto mit vollem Warenkorb an der Block-Kasse
  (403 `bs_connector_blocked`) und an der klassischen Kasse abgelehnt (frische Abfrage);
  Nachnahme bei Bootsschul-Produkten ausgeblendet (beide Kassen, Store API lehnt `cod` ab);
  Bestellung je Art (Kurs, Verlängerung, Produkt, Kurs mit Zusatzprodukt, Variante) mit
  Menge 3 → Notizen „eingeplant“/„hinterlegt“, 5 Batches à 3 Codes, Zeilen in „Bestellungen
  aus dem Shop“; **kein Codewert** in Shop-Datenbank, Mails, Logs, Bestellseiten;
  Nachnahme im Backend auf „In Bearbeitung“ → keine Zustellung, eine Notiz, nach
  „Abgeschlossen“ geliefert; App gestoppt → Wiederholung, danach genau ein Batch;
  verlorene Antwort (zweiter Versuch) → Nachfrage findet den Batch, kein zweiter;
  Tageskontingent 2 bei Menge 3 → sofort „fehlgeschlagen“, Bestellnotiz, Alarm-Mails
  in Shop und App, nach Anheben „Erneut zustellen“ → geliefert; Zuordnung nach dem
  Einplanen geändert → ursprünglicher Auftrag geliefert; Menge 501 → auf 500 begrenzt.
- 5: Teil-Rückerstattung Menge 1 → genau ein unbenutzter Code gesperrt, Batch `partially_voided`;
  Rückerstattung nur als Betrag → nichts gesperrt, Notiz und Alarm-Mail; zwei Codes
  eingelöst, dann Storno → eingelöste gültig, alle übrigen gesperrt; „In Wartestellung“ vor
  der Zustellung → `deferred`, wieder „In Bearbeitung“ → geliefert mit gleicher Menge.
- Abschluss: Deinstallation entfernt Einstellungen, Katalog-/Status-Zwischenspeicher und
  offene Aktionen der Gruppe `bs-connector`; Zustellstatus der Bestellungen bleibt.

**Gefunden und behoben** (Unit-Tests in `tests/Unit/ConnectorOrdersAndCheckoutTest.php`)

- Schule B konnte einen Kurs- oder Verlängerungscode für einen nicht freigeschalteten Kurs
  in den Warenkorb legen; erst Warenkorb und Kasse sperrten. Jetzt verweigert schon
  „In den Warenkorb“ (klassisch und Store API).
- Stornierte Bestellung mit **bereits gelieferten** Codes wieder auf „In Bearbeitung“:
  keine Nachlieferung (richtig), aber auch keine Notiz und keine Alarm-Mail – die Meldung
  griff nur bei Positionen, die vor der Lieferung storniert wurden. Jetzt merkt sich das
  Storno jede eingeplante Position (`_bs_cancelled`), gemeldet wird einmalig. Die Box
  „Bootsschul-Codes“ zeigt solche Positionen als „storniert, unbenutzte Codes gesperrt“
  und zurückgestellte als „zurückgestellt“ statt `deferred`.
- Abgelehnte Konten wurden beim Einkauf aufgefordert, sich unter „Mein Konto“ zu
  verknüpfen, obwohl das nicht möglich ist. Jetzt Hinweis auf den Support wie in „Mein Konto“.

**Nicht geprüft**

- MySQL/MariaDB (nur SQLite) und damit auch der normale Action-Scheduler-Lauf
  (WP-Cron/Queue-Runner, `wp action-scheduler run`): die Beanspruchungsabfrage lief
  mit dem Drop-in nicht korrekt, Aktionen wurden einzeln ausgeführt. Bitte auf Staging
  prüfen, dass die Gruppe `bs-connector` ohne Eingriff abgearbeitet wird.
- Echte Zahlart mit Zahlungsbestätigung (`woocommerce_payment_complete`, z. B. Stripe-Testmodus).
- Echter Mailversand und Inhalt der Mails der App bei „Als neue Schule freigeben“ und an
  Inhaber bei „Bestehender Schule zuordnen“ (die Testschule hatte keinen Inhaber; die
  Freigabe-Mail fiel vor dem Umstellen des Log-Levels nicht ins Log).
- Cron für `schedule:run` (Ausgang von Hand ausgelöst), `app:check-production`.
- Wartezeiten 1/5/30 Minuten in Echtzeit (vorgezogen), Gleichzeitigkeit/Last.
- Löschen der Plugin-Dateien (Deinstallation mit `--skip-delete`, weil das Plugin aus dem
  Repository verlinkt war).
- Darstellung im Browser (nur HTTP-Antworten und HTML ausgewertet), Sprachen außer der
  englischen WordPress-Oberfläche.

## Ergebnis Staging-Lauf in Docker (16.11.2026, WordPress 7.1.4, WooCommerce 11.3.0, Yoast SEO 28.8, MySQL 8.4.13)

Die Checkliste oben einmal gegen einen Staging-Shop in Docker
([`deploy/staging-shop/`](../../deploy/staging-shop/README.md)) und die echte App
(PHP 8.3, PostgreSQL 16) durchgespielt. **Kein Ersatz für den Staging-Test mit dem
Theme, den Zahlarten und Plugins des echten Shops** – Abweichungen unten.

**Aufbau**

- `docker compose`: MySQL 8.4, WordPress `latest` mit Apache (PHP 8.3.36), WP-CLI,
  Mailpit (SMTP + Web-Oberfläche); Plugin per Bind-Mount aus dem Repository,
  Geheimnisse als Konstanten über `WORDPRESS_CONFIG_EXTRA`. WordPress mit `wp core update`
  auf dem neuesten Stand (7.1.4), WooCommerce 11.3.0 und Yoast SEO 28.8 (jeweils neueste
  Version von wordpress.org), Oberfläche Deutsch, HPOS aktiv, Permalinks „Beitragsname“,
  Theme Twenty Twenty-Five (Block-Warenkorb und -Kasse), DE/EUR.
- Mails von Shop **und** App per SMTP an Mailpit (echter Versand, Inhalte in Mailpit geprüft).
- WP-Cron aktiv (Standard); Action Scheduler lief ohne Eingriff über WP-Cron, zusätzlich
  `wp action-scheduler run --group=bs-connector` – der aus der CI bekannte Fehler
  „group does not exist“ trat mit MySQL nicht auf. App-Scheduler und Ausgang per
  `php artisan schedule:run` bzw. `app:commerce-outbox` von Hand (kein Cron-Dienst).
- Zahlarten: Überweisung, Nachnahme und **„Testzahlung“ als Ersatz** für ein Gateway
  im Testmodus (MU-Plugin `deploy/staging-shop/mu-plugins/bs-staging-testpay.php`, ruft
  `$order->payment_complete()` auf, auch in der Block-Kasse). Stripe/WooPayments brauchen
  Konten und Schlüssel, die es hier nicht gab.
- Abläufe im Browser (Playwright: Registrierung, „Mein Konto“, Block-Kasse, Backend,
  Superadmin und Admin der App), über die Store API und WP-CLI.

**Bestanden**

- Vorbereitung: `app:check-production` meldet „Geheimnisse der Shop-Anbindung“ `ok`; Plugin
  aktiv, Katalogabruf signiert. Ohne Konstanten zeigt der Tab „Bootsschule“ die `define()`-Zeilen
  statt Geheimnis-Feldern, „Geheimnisse aus der Datenbank erlauben“ aus; mit Konstanten keine
  Felder dafür, kein Geheimnis im HTML. Update von 1.3.0 mit Geheimnissen nur in der Datenbank:
  Anbindung läuft weiter (`wp bs-connector resync` HTTP 200), Einstellung eingeschaltet, Hinweis
  „beim Update … eingeschaltet“; nach Umzug in `wp-config.php` und Ausschalten verschwindet er.
  Gruppe `bs-connector` nach den Tests ohne offene Aktion.
- 1: Registrierung (mit Bestätigungsmail aus Mailpit) → `pending_review`, kein App-Konto;
  Adresse des Admins von Schule A → `awaiting_link`; Code über Admin → Zugangscodes →
  „Verknüpfungscode erzeugen“ → `linked`, „Verknüpft mit: Bootsschule Müller“; fünf falsche
  Codes → danach „Zu viele Versuche“ (429); sechsmal „Mein Konto“ neu laden → kein weiterer
  `GET /accounts/{id}`; abgelaufener Code abgelehnt; Shop-Manager ohne Tab „Bootsschule“;
  „Konto neu abgleichen“: Knopf nur für Administratoren und registrierte Konten, Hinweis
  „Abgleich eingeplant“, Aktion mit `force`, Plattform zeigt wieder die Firma aus dem Shop,
  `wp bs-connector resync` → „abgeglichen (HTTP 200)“.
- 2: „Als neue Bootsschule freigeben“ → Einladungsmail mit Passwort-Link, `linked`, Einkauf
  möglich (siehe Fund 1); „Bestehender Bootsschule zuordnen“ → Mail „Neuer Admin für Ihre
  Bootsschule (Shop)“ an die Inhaberin; „Ablehnen“ → „Mein Konto“ ohne Formular,
  `POST /accounts/{id}/link` → 409 `ACCOUNT_REJECTED`.
- 3: Schule C → nach `schedule:run` Kundenkonto im Shop (`_bs_provisioned`), Shop-Mail mit
  Passwort-Link; Schule D mit Adresse eines fremden Shopkontos → Shopkonto unverändert,
  `needs_link`, Hinweis im Codes-Tab; Plugin deaktiviert → Versuche nach 1/5/30 Minuten
  (Zeitpunkte geprüft, Wartezeit vorgezogen), „fehlgeschlagen“, Alarm-Mail, nach Reaktivierung
  „Erneut versuchen“ → Kundenkonto angelegt.
- 4: Gast, abgelehntes Konto und Schule B (SBF-SEE abgeschaltet: Kurs- und Verlängerungscode)
  → „In den Warenkorb“ verweigert (Produktseite und Store API); Nachnahme bei Bootsschul-Produkten
  ausgeblendet, sonst angeboten; Schule A kauft in der **Block-Kasse mit Testzahlung** je
  Kurscode, Verlängerung, Produktcode, Kurs mit Zusatzprodukt und Variante Menge 3 → Notizen
  „eingeplant“ und „… im Codebestand der Bootsschule hinterlegt“ ohne Eingriff (WP-Cron),
  Codes und Zeilen unter Admin → Zugangscodes; kein Codewert in der Shop-Datenbank
  (mysqldump), in Logs oder Mails; Nachnahme im Backend auf „In Bearbeitung“ → eine Notiz,
  keine Zustellung, nach „Abgeschlossen“ geliefert; App gestoppt → Wiederholung, genau ein
  Codepaket; Tageskontingent 2 bei Menge 3 → sofort „fehlgeschlagen“, Alarm-Mails in Shop und
  App, „Erneut zustellen“ nach Anheben → geliefert; Zuordnung nach dem Einplanen geändert →
  ursprüngliches Produkt geliefert; Kontoabgleich bei sichtbarem Firmenfeld: Bestellung ohne
  Änderung → kein `PATCH`, geänderte Firma → genau ein `PATCH`, neue Firma auf der Plattform,
  danach wieder kein `PATCH`.
- 5: Teil-Rückerstattung Menge 1 → ein Code gesperrt (`partially_voided`); nur Betrag → keine
  Sperre, Notiz und Alarm-Mail; Code eingelöst, dann Storno → eingelöster bleibt gültig, übrige
  gesperrt; „In Wartestellung“ vor der Zustellung → `deferred`, wieder „In Bearbeitung“ →
  geliefert mit gleicher Menge; Storno wieder „In Bearbeitung“ → keine Nachlieferung, Notiz,
  Alarm-Mail.
- Abschluss: Deinstallation entfernt Einstellungen, Zwischenspeicher und offene Aktionen der
  Gruppe `bs-connector`; Zustellstatus der Bestellungen bleibt.

**Gefunden und behoben** (Plugin 1.4.3, Tests in `tests/Unit/ConnectorAccountsAndSettingsTest.php`)

1. Nach der Freigabe durch den Superadmin zeigte der Shop bis zu 5 Minuten „noch nicht
   freigeschaltet“ und verweigerte „In den Warenkorb“: auch der Status ohne Einkauf wurde
   5 Minuten zwischengespeichert. Jetzt 60 Sekunden für Konten ohne Einkaufsfreigabe
   (im Lauf: Einkauf 60 s nach der Freigabe möglich), freigeschaltete weiter 5 Minuten.
2. Neue WooCommerce-Installationen blenden „Firmenname“ an der Kasse aus. Jede Bestellung
   über die Block-Kasse speicherte dann eine leere Firma im Kundenkonto (einmal `PATCH` ohne
   Firma; die Plattform behält die alte, der Shop verliert sie). In 1.4.3 Hinweis für
   Administratoren im Backend; ab 1.4.4 zeigt das Plugin das Feld selbst als „optional“
   an (Hinweis entfällt, der Staging-Aufbau setzt die Option nicht mehr); Punkt in der
   Vorbereitung angepasst.
3. Einstellungsseite ohne Konstanten: WooCommerce gibt die Beschreibung durch `wptexturize`
   aus, die `define()`-Zeilen erschienen mit typografischen Anführungszeichen (‚…‘) – kopiert
   ein PHP-Fehler in `wp-config.php`. Jetzt als `<code>` mit geraden Anführungszeichen.

**Nicht geprüft**

- Echte Zahlungsdienste (Stripe/WooPayments im Testmodus, Webhooks): keine Konten/Schlüssel;
  ersetzt durch „Testzahlung“ mit `payment_complete()`.
- Cron-Dienst für `schedule:run` und Queue-Worker als Dienst (von Hand ausgelöst);
  Wartezeiten 1/5/30 Minuten in Echtzeit (vorgezogen); Last und Gleichzeitigkeit.
- Löschen der Plugin-Dateien (Deinstallation mit `--skip-delete`, Plugin per Bind-Mount).
- Theme, weitere Plugins und Zahlarten des echten Shops; HTTPS (alles über `http://127.0.0.1`).
- Aufrufe von WordPress nach wordpress.org aus dem Container (Updates, Übersetzungen) – in
  dieser Umgebung ohne DNS; Pakete und Sprachdateien wurden auf dem Host geladen
  (`packages/`, siehe README des Aufbaus).
