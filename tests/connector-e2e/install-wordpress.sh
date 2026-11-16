#!/usr/bin/env bash
# Richtet WordPress mit WooCommerce und dem Connector-Plugin für den
# Connector-E2E-Test ein (CI: .github/workflows/connector-wordpress.yml).
# Schritte, deren Ergebnis schon da ist, werden übersprungen: fehlt der Kern,
# lädt WP-CLI ihn (WP_VERSION); fehlt wp-config.php, legt WP-CLI sie für MySQL
# an; fehlt WooCommerce, kommt es von wordpress.org (WC_VERSION).
#
# Umgebung:
#   WP_PATH, WP_URL                Zielverzeichnis und Adresse des Shops
#   WP_VERSION, WC_VERSION         gepinnte Versionen (nur für Downloads)
#   WP_DB_NAME, WP_DB_USER, WP_DB_PASSWORD, WP_DB_HOST   (nur für wp config create)
#   APP_URL                        Adresse der Laravel-App
#   COMMERCE_SHOP_SECRET, COMMERCE_OUTBOUND_SECRET       gemeinsame Geheimnisse
#   WP_CLI_BIN                     WP-CLI-Aufruf (Standard: wp)
#   WP_DEBUG_LOG_FILE, BS_E2E_MAIL_LOG                   Protokolldateien
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
: "${WP_PATH:?}" "${WP_URL:?}" "${APP_URL:?}" "${COMMERCE_SHOP_SECRET:?}" "${COMMERCE_OUTBOUND_SECRET:?}"
: "${WP_CLI_BIN:=wp}" "${WP_DEBUG_LOG_FILE:=$WP_PATH/wp-content/debug.log}" "${BS_E2E_MAIL_LOG:=$WP_PATH/wp-content/bs-e2e-mail.log}"

# shellcheck disable=SC2086 # WP_CLI_BIN darf aus mehreren Wörtern bestehen
wpc() { $WP_CLI_BIN --path="$WP_PATH" "$@"; }

mkdir -p "$WP_PATH"
if [ ! -f "$WP_PATH/wp-load.php" ]; then
    wpc core download --version="${WP_VERSION:?}" --locale=en_US
fi

if [ ! -f "$WP_PATH/wp-config.php" ]; then
    wpc config create --dbname="${WP_DB_NAME:?}" --dbuser="${WP_DB_USER:?}" --dbpass="${WP_DB_PASSWORD:-}" --dbhost="${WP_DB_HOST:?}" --skip-check
    wpc config shuffle-salts
fi

# Plugin-Konfiguration ausschließlich über wp-config.php (docs/commerce/README.md).
wpc config set BS_CONNECTOR_APP_URL "$APP_URL"
wpc config set BS_CONNECTOR_KEY_ID shop
wpc config set BS_CONNECTOR_SECRET "$COMMERCE_SHOP_SECRET"
wpc config set BS_CONNECTOR_INBOUND_KEY_ID app
wpc config set BS_CONNECTOR_INBOUND_SECRET "$COMMERCE_OUTBOUND_SECRET"
wpc config set BS_E2E_MAIL_LOG "$BS_E2E_MAIL_LOG"
wpc config set WP_DEBUG true --raw
wpc config set WP_DEBUG_LOG "$WP_DEBUG_LOG_FILE"
wpc config set WP_DEBUG_DISPLAY false --raw
wpc config set AUTOMATIC_UPDATER_DISABLED true --raw
wpc config set WP_ENVIRONMENT_TYPE local
# Action Scheduler läuft nur über "wp action-scheduler run" (kein WP-Cron
# nebenher, der Aufgaben zu einem anderen Zeitpunkt abarbeitet).
wpc config set DISABLE_WP_CRON true --raw

if ! wpc core is-installed 2>/dev/null; then
    wpc core install --url="$WP_URL" --title="Connector E2E" --admin_user=admin \
        --admin_password="$(php -r 'echo bin2hex(random_bytes(12));')" --admin_email=admin@example.test --skip-email
fi

if [ ! -d "$WP_PATH/wp-content/plugins/woocommerce" ]; then
    wpc plugin install woocommerce --version="${WC_VERSION:?}"
fi
wpc plugin activate woocommerce
# Ab hier nur noch lokale Aufrufe (App, Shop selbst): keine Anfragen von
# WordPress/WooCommerce nach außen, die den Test verlangsamen oder stören.
wpc config set WP_HTTP_BLOCK_EXTERNAL true --raw
wpc config set WP_ACCESSIBLE_HOSTS "127.0.0.1,localhost"

ln -sfn "$REPO/integrations/woocommerce-connector" "$WP_PATH/wp-content/plugins/woocommerce-connector"
mkdir -p "$WP_PATH/wp-content/mu-plugins"
cp "$HERE/wp/mu-plugin-mail.php" "$WP_PATH/wp-content/mu-plugins/bs-e2e-mail.php"

wpc rewrite structure '/%postname%/'
wpc eval-file "$HERE/wp/setup-shop.php"
wpc plugin activate woocommerce-connector
wpc rewrite flush

wpc core version --extra
wpc plugin list --fields=name,status,version
