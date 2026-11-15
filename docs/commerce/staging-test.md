# Staging-Test der Shop-Anbindung

Das Plugin `integrations/woocommerce-connector/` wurde bisher nur gegen
simulierte Shop-Aufrufe getestet. Diese Checkliste spielt die Abläufe aus
[`README.md`](README.md) einmal gegen ein echtes WordPress/WooCommerce und
eine Staging-Instanz der Plattform durch. Einmal vor dem Livebetrieb und nach
größeren WooCommerce-Updates abhaken.

## Vorbereitung

- [ ] Staging-Plattform mit `COMMERCE_SHOP_SECRET`, `COMMERCE_OUTBOUND_SECRET`
      (je `openssl rand -hex 32`), `COMMERCE_SHOP_URL` und `COMMERCE_ALERT_EMAIL`.
      `php artisan app:check-production` meldet bei „Geheimnisse der
      Shop-Anbindung“ `ok`.
- [ ] Cron für `schedule:run` läuft (sonst wird `app:commerce-outbox` nicht gesendet).
- [ ] WordPress ≥ 6.5, WooCommerce ≥ 8 mit HPOS, PHP ≥ 8.1, Permalinks **nicht** „Einfach“.
- [ ] Plugin kopiert und aktiviert. Geheimnisse in `wp-config.php` (siehe `readme.txt`).
- [ ] Testzahlart mit Zahlungsbestätigung (z. B. Stripe-Testmodus) und zusätzlich „Nachnahme“ aktiv.
- [ ] Zwei Testschulen auf der Plattform: **A** (aktiv, Kurs SBF-SEE freigeschaltet), **B** (aktiv, SBF-SEE abgeschaltet).
- [ ] Shop-Produkte: Kurscode SBF-SEE, Verlängerungscode SBF-SEE, ein Produktcode, ein Kurscode mit Zusatzprodukt, ein variables Produkt.
- [ ] Prüfen: `WooCommerce → Status → Geplante Aktionen`, Gruppe `bs-connector`, ist nach den Bestelltests leer bzw. „Abgeschlossen“.

## 1. Bestehende Schule verknüpft sich

- [ ] Neues Shopkonto mit einer Adresse **ohne** App-Konto registrieren → Superadmin „Shopkonten“ zeigt `pending_review`; auf der Plattform entsteht **kein** App-Konto (Selbstregistrierung mit der Adresse bleibt möglich).
- [ ] Shopkonto mit der Adresse eines bestehenden Admins von Schule A → ebenfalls `pending_review` nach außen, Superadmin sieht `awaiting_link`, **keine** automatische Verknüpfung.
- [ ] In Schule A: Admin → Codes → „Shop verknüpfen“ → Code im Shop unter „Mein Konto → Bootsschule verknüpfen“ eingeben → Status `linked`, Schulname im Shop sichtbar.
- [ ] Fünf falsche Codes in Folge → sechster Versuch abgelehnt (429), Meldung im Shop verständlich.
- [ ] „Mein Konto → Bootsschule verknüpfen“ mehrfach neu laden → im Zugriffsprotokoll der Plattform höchstens ein `GET /accounts/{id}` je 5 Minuten (bzw. 60 s bei Fehler).
- [ ] Als Shop-Manager (ohne Administratorrechte) angemeldet: Tab „Bootsschule“ unter WooCommerce → Einstellungen fehlt. Als Administrator mit Konstanten in `wp-config.php`: die betroffenen Felder sind nicht editierbar.
- [ ] Abgelaufener Code (> 15 Minuten) wird abgelehnt.

## 2. Neue Schule über den Shop

- [ ] Neues Shopkonto → Superadmin „Neue Schule anlegen“ → Person erhält Passwort-Link, Shopkonto `linked`, Einkauf möglich.
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
- [ ] Verknüpftes Konto von Schule A kauft je ein Produkt jeder Art, Menge 3, Testzahlung → Bestellnotizen „eingeplant“ und „zugestellt“; Schule A sieht unter Admin → Codes die Codes und die Zeile in „Bestellungen aus dem Shop“.
- [ ] Im Shop (Bestellung, Notizen, Mails, Logs) erscheint **kein** Codewert.
- [ ] Variables Produkt: Zustellung nach der Zuordnung am Hauptprodukt.
- [ ] Bestellung im Backend mit Zahlart Nachnahme auf „In Bearbeitung“ → **keine** Zustellung, einmalige Notiz; nach „Abgeschlossen“ wird zugestellt.
- [ ] Plattform während der Zustellung kurz nicht erreichbar → Wiederholung, am Ende genau **ein** Code-Paket (nicht doppelt).
- [ ] `COMMERCE_DAILY_CODE_LIMIT` auf 2 setzen, Menge 3 bestellen → sofort „fehlgeschlagen“, Alarm-Mail; Limit anheben, „Erneut zustellen“ → geliefert.
- [ ] Produktzuordnung nach dem Kauf ändern → bereits eingeplante Lieferung bleibt beim ursprünglichen Auftrag.

## 5. Rückerstattung und Storno

- [ ] Teil-Rückerstattung über Positionsmenge 1 → bei der Schule 1 Code gesperrt (der zuletzt erzeugte unbenutzte).
- [ ] Einen Code einlösen, dann Bestellung stornieren → eingelöster Code bleibt gültig, alle übrigen gesperrt.
- [ ] Rückerstattung nur als Betrag (ohne Menge) → keine Sperre, aber Bestellnotiz und Alarm-Mail.
- [ ] Bestellung „In Wartestellung“ vor der Zustellung → Status `deferred`; wieder „In Bearbeitung“ → Zustellung mit unveränderter Menge.
- [ ] Stornierte Bestellung wieder auf „In Bearbeitung“ → keine Nachlieferung, Notiz und Alarm-Mail.

## Abschluss

- [ ] Plugin deaktivieren und löschen → `uninstall.php` entfernt Einstellungen, Zwischenspeicher und geplante Aktionen der Gruppe `bs-connector`; Bestellungen samt Zustellstatus bleiben.
- [ ] Ergebnis (Datum, WooCommerce-/WordPress-Version, Auffälligkeiten) in `docs/commerce/README.md` unter „Test“ nachtragen.
