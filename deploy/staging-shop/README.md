# Staging-Shop (WordPress, WooCommerce, Yoast, Mailpit)

Ein Shop zum Durchspielen der Checkliste [`docs/commerce/staging-test.md`](../../docs/commerce/staging-test.md)
gegen eine Staging-Instanz der Plattform: MySQL 8.4, WordPress (neueste Version, Apache),
WooCommerce und Yoast SEO (neueste Versionen), das Connector-Plugin aus
`integrations/woocommerce-connector` (Bind-Mount, Änderungen wirken sofort), WP-CLI und
Mailpit als SMTP-Server mit Web-Oberfläche. **Nur für Tests**, nicht für den Livebetrieb.

## Voraussetzungen

- Linux mit Docker und Docker Compose v2 (alle Dienste laufen im Host-Netz).
- Freie Ports: WordPress `8080`, Mailpit `8025` (Oberfläche) und `1025` (SMTP), MySQL `3306`
  (nur `127.0.0.1`); änderbar in `.env`.
- Die Plattform (Staging) ist vom Shop unter `BS_CONNECTOR_APP_URL` erreichbar und erreicht
  den Shop unter `WP_URL`.

## Starten

```bash
cd deploy/staging-shop
cp .env.example .env
# ausfüllen: WP_ADMIN_PASSWORD, MYSQL_PASSWORD, MYSQL_ROOT_PASSWORD,
# COMMERCE_SHOP_SECRET / COMMERCE_OUTBOUND_SECRET (je: openssl rand -hex 32),
# BS_CONNECTOR_APP_URL (Adresse der Plattform), auf einem Server WP_URL
./setup.sh
```

`setup.sh` startet die Container (offizielle Images `mysql:8.4`, `wordpress:latest`,
`wordpress:cli`, `axllent/mailpit`), installiert WordPress, bringt es mit `wp core update`
auf den neuesten Stand, installiert bzw. aktualisiert WooCommerce und Yoast SEO, stellt
Deutsch ein, aktiviert HPOS, Permalinks „Beitragsname“, Deutschland/EUR, Firmenfeld an der
Kasse, die Zahlarten Überweisung, Nachnahme und „Testzahlung“ und aktiviert den Connector.
Am Ende gibt es die Versionen aus. Es kann jederzeit erneut laufen (z. B. für Updates).

- Shop: `http://127.0.0.1:8080/wp-admin/` (Benutzer `admin`, Passwort aus `.env`)
- Mailpit: `http://127.0.0.1:8025/` – alle Mails des Shops (MU-Plugin `bs-staging-smtp.php`)
- WP-CLI: `docker compose run --rm wpcli wp <befehl>`, z. B.
  `docker compose run --rm wpcli wp action-scheduler run --group=bs-connector`

## Plattform anbinden

In der `.env` der Staging-Plattform dieselben Geheimnisse und die Shop-Adresse setzen, die
Mails ebenfalls an Mailpit schicken:

```dotenv
COMMERCE_SHOP_SECRET=<wie oben>
COMMERCE_OUTBOUND_SECRET=<wie oben>
COMMERCE_SHOP_URL=http://127.0.0.1:8080
COMMERCE_ALERT_EMAIL=alarm@example.test
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
```

Danach `php artisan config:clear` (bzw. `optimize`), Cron für `schedule:run` und den
Queue-Worker wie im Betrieb ([`deploy/README.md`](../README.md)). Auf dem Shop-Tab
WooCommerce → Einstellungen → Bootsschule prüft „Verbindung testen“ die Anbindung.

## Hinweise

- **Testzahlung:** `mu-plugins/bs-staging-testpay.php` bestätigt die Zahlung sofort mit
  `$order->payment_complete()` – wie ein Gateway im Testmodus, ohne Zahlungsdienst. Für
  einen Test mit echtem Zahlungsdienst Stripe oder WooPayments im Testmodus mit eigenem
  Konto installieren. In Produktion (`WP_ENVIRONMENT_TYPE=production`) ist die Zahlart aus.
- **Geheimnisse** stehen nur in `.env` und kommen als Konstanten in `wp-config.php`
  (`WORDPRESS_CONFIG_EXTRA`). Sind sie leer, fehlen die Konstanten – so lässt sich die
  Einstellungsseite ohne Konstanten prüfen.
- **Von außen erreichbar machen:** `WP_URL` auf die öffentliche Adresse setzen und
  `WP_PORT` freigeben (besser hinter einem TLS-Proxy). Mailpit lauscht standardmäßig nur auf
  `127.0.0.1`; `MAILPIT_BIND=0.0.0.0` macht Oberfläche und SMTP ohne Anmeldung öffentlich.
- **Ohne Zugang zu wordpress.org** (Proxy, Firewall): Plugin-Pakete als
  `packages/<slug>.<version>.zip` und Sprachdateien unter `packages/languages/` ablegen
  (z. B. per `curl` von `downloads.wordpress.org`); `setup.sh` installiert dann von dort.
  Für WP-CLI kann `WPCLI_HTTPS_PROXY` gesetzt werden.
- **Stoppen / wieder starten:** `docker compose stop` bzw. `docker compose start`; alles
  löschen: `docker compose down -v`.
