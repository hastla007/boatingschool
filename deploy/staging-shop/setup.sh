#!/usr/bin/env bash
# Richtet den Staging-Shop ein (idempotent, beliebig oft aufrufbar):
# Container starten, WordPress installieren und auf die neueste Version
# bringen, WooCommerce und Yoast SEO (neueste Versionen) installieren und
# aktivieren, HPOS, Permalinks „Beitragsname“, DE/EUR, Zahlarten, Connector.
# Voraussetzung: .env (siehe .env.example). Anleitung: README.md.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
[ -f .env ] || { echo ".env fehlt (cp .env.example .env)" >&2; exit 1; }
set -a
# shellcheck disable=SC1091
. ./.env
set +a
: "${WP_ADMIN_PASSWORD:?WP_ADMIN_PASSWORD in .env setzen}"
: "${BS_CONNECTOR_APP_URL:?BS_CONNECTOR_APP_URL in .env setzen}"
[ ${#COMMERCE_SHOP_SECRET} -ge 32 ] && [ ${#COMMERCE_OUTBOUND_SECRET} -ge 32 ] ||
    { echo "COMMERCE_SHOP_SECRET und COMMERCE_OUTBOUND_SECRET in .env setzen (je openssl rand -hex 32)" >&2; exit 1; }

wp() { docker compose run --rm -T wpcli wp "$@"; }

docker compose up -d --wait db mailpit wordpress
# Der WordPress-Container kopiert die Kerndateien beim ersten Start.
for _ in $(seq 1 60); do
    docker compose exec -T wordpress test -f /var/www/html/wp-config.php && break
    sleep 1
done

if ! wp core is-installed 2>/dev/null; then
    wp core install --url="${WP_URL:-http://127.0.0.1:8080}" --title="Bootsschule Staging-Shop" \
        --admin_user="${WP_ADMIN_USER:-admin}" --admin_password="$WP_ADMIN_PASSWORD" \
        --admin_email="${WP_ADMIN_EMAIL:-admin@example.test}" --skip-email
fi

wp core update
wp core update-db
# Ohne Zugang zu downloads.wordpress.org: Pakete vorher nach packages/ legen
# (<slug>.<version>.zip, Sprachdateien unter packages/languages/), siehe README.md.
for plugin in woocommerce wordpress-seo; do
    zip="$(find packages -maxdepth 1 -name "$plugin.*.zip" 2>/dev/null | sort -V | tail -n 1)"
    if [ -n "$zip" ]; then
        wp plugin install "/opt/$zip" --force
    elif wp plugin is-installed "$plugin"; then
        wp plugin update "$plugin"
    else
        wp plugin install "$plugin"
    fi
done

# Sprache vor dem Aktivieren: WooCommerce legt seine Seiten dann gleich auf Deutsch an.
if [ -n "${WP_LOCALE:-}" ] && [ "$WP_LOCALE" != en_US ]; then
    if [ -d packages/languages ]; then
        docker compose cp packages/languages/. wordpress:/var/www/html/wp-content/languages/
        docker compose exec -T wordpress chown -R www-data:www-data /var/www/html/wp-content/languages
        wp site switch-language "$WP_LOCALE"
    else
        wp language core install "$WP_LOCALE" --activate || true
        wp language plugin install woocommerce wordpress-seo "$WP_LOCALE" || true
    fi
fi
wp plugin activate woocommerce wordpress-seo

wp wc hpos enable || true
wp rewrite structure '/%postname%/'
wp eval-file /opt/staging/setup-shop.php
wp plugin activate woocommerce-connector
wp rewrite flush

echo
echo "WordPress $(wp core version), PHP/MySQL:"
wp eval 'global $wpdb; echo "PHP ".PHP_VERSION.", MySQL ".$wpdb->db_version()."\n";'
wp plugin list --fields=name,status,version
echo "Shop:    ${WP_URL:-http://127.0.0.1:8080}/wp-admin/  (Benutzer ${WP_ADMIN_USER:-admin})"
echo "Mailpit: http://127.0.0.1:${MAILPIT_UI_PORT:-8025}/"
