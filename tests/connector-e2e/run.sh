#!/usr/bin/env bash
# Connector-E2E-Test: echtes WordPress + WooCommerce + Connector-Plugin gegen
# die laufende Laravel-App. Voraussetzung: beide Server laufen, WordPress ist
# mit install-wordpress.sh eingerichtet. Ablauf:
#   1. Testschule mit Schuladmin und Verknüpfungscode in der App
#   2. Kundenkonto im Shop, Bestätigungslink aus der Mail öffnen,
#      Action Scheduler → Konto in der App angemeldet (POST /accounts)
#   3. Anmeldung im Shop, Verknüpfungscode unter „Mein Konto“ eingeben
#      → Shopkonto in der App mit der Testschule verknüpft
#   4. Produkt mit Kurscode-Zuordnung (Kurs aus dem Katalog der App)
#   5. Bestellung, bezahlt und abgeschlossen; Action Scheduler
#      (wp action-scheduler run --group=bs-connector) bis zur Zustellung
#   6. Prüfung: Positionen zugestellt, Bestellnotiz, Codes der Schule in der App
# Jeder Fehlschlag endet mit Exit ≠ 0 und gibt die Protokolle aus.
#
# Umgebung: WP_PATH, WP_URL, APP_URL; optional WP_CLI_BIN (Standard: wp),
# WP_DEBUG_LOG_FILE, BS_E2E_MAIL_LOG, LARAVEL_LOG, E2E_EXTRA_LOGS (weitere
# Protokolldateien, durch Leerzeichen getrennt), E2E_TIMEOUT (Sekunden je
# Warteschritt, Standard 60), E2E_COURSE (bevorzugter Kurscode, Standard SRC),
# E2E_QUANTITY (Standard 3), AS_RUN (Befehl für den Action-Scheduler-Lauf).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
: "${WP_PATH:?}" "${WP_URL:?}" "${APP_URL:?}"
: "${WP_CLI_BIN:=wp}" "${WP_DEBUG_LOG_FILE:=$WP_PATH/wp-content/debug.log}" "${BS_E2E_MAIL_LOG:=$WP_PATH/wp-content/bs-e2e-mail.log}"
: "${LARAVEL_LOG:=$REPO/storage/logs/laravel.log}" "${E2E_EXTRA_LOGS:=}" "${E2E_TIMEOUT:=60}"
: "${E2E_COURSE:=SRC}" "${E2E_QUANTITY:=3}"
WP_URL="${WP_URL%/}"
APP_URL="${APP_URL%/}"

EMAIL="e2e-admin-$(date +%s)@example.test"
PASSWORD="$(php -r 'echo bin2hex(random_bytes(12));')"
WORK="$(mktemp -d)"
COOKIES="$WORK/cookies.txt"
ORDER_ID=""

# shellcheck disable=SC2086 # WP_CLI_BIN darf aus mehreren Wörtern bestehen
wpc() { $WP_CLI_BIN --path="$WP_PATH" "$@"; }
step() { printf '\n==> %s\n' "$*"; }

dump_logs() {
    set +e
    echo
    echo "################ Protokolle ################"
    if [ -n "$ORDER_ID" ]; then
        echo "--- Bestellung $ORDER_ID"
        wpc eval-file "$HERE/wp/check-order.php" "$ORDER_ID"
    fi
    echo "--- Offene/fehlgeschlagene Aktionen (bs-connector)"
    wpc action-scheduler action list --group=bs-connector --fields=id,hook,status,scheduled_date,args --per_page=50 2>&1 | tail -n 60
    local log
    for log in "$LARAVEL_LOG" "$WP_DEBUG_LOG_FILE" "$BS_E2E_MAIL_LOG" $E2E_EXTRA_LOGS "$WP_PATH"/wp-content/uploads/wc-logs/bs-connector*.log; do
        [ -f "$log" ] || continue
        echo "--- $log (Ende)"
        tail -n 200 "$log"
    done
}

# Fehlschlag (auch ein abgebrochener Befehl): Protokolle ausgeben, Exit ≠ 0.
fail() {
    echo "FEHLER: $*" >&2
    exit 1
}
trap 'rc=$?; [ "$rc" -eq 0 ] || dump_logs; rm -rf "$WORK"; exit "$rc"' EXIT

# Wartet mit Zeitlimit, bis der Befehl erfolgreich ist (kein festes Warten).
poll() {
    local what="$1" deadline=$((SECONDS + E2E_TIMEOUT))
    shift
    until "$@"; do
        [ "$SECONDS" -lt "$deadline" ] || return 1
        sleep 1
    done
    echo "    ok: $what"
}

as_run() {
    if [ -n "${AS_RUN:-}" ]; then
        eval "$AS_RUN"
    else
        wpc action-scheduler run --group=bs-connector --batch-size=25 --batches=4
    fi
}

up() { curl -fs -o /dev/null --max-time 5 "$1"; }

step "Server erreichbar"
poll "App $APP_URL/up" up "$APP_URL/up" || fail "App unter $APP_URL nicht erreichbar"
poll "Shop $WP_URL/wp-login.php" up "$WP_URL/wp-login.php" || fail "WordPress unter $WP_URL nicht erreichbar"

step "1. Testschule und Verknüpfungscode in der App"
SETUP="$(cd "$REPO" && php tests/connector-e2e/app-setup.php "$EMAIL")" || fail "app-setup.php fehlgeschlagen"
TENANT_ID="$(php -r 'echo json_decode($argv[1], true)["tenant_id"];' "$SETUP")"
LINK_CODE="$(php -r 'echo json_decode($argv[1], true)["link_code"];' "$SETUP")"
echo "    Schule $TENANT_ID, Schuladmin $EMAIL"

step "2. Kundenkonto im Shop, E-Mail-Bestätigung, Anmeldung bei der App"
: > "$BS_E2E_MAIL_LOG"
CUSTOMER_ID="$(wpc eval-file "$HERE/wp/create-customer.php" "$EMAIL" "$PASSWORD" | tail -n 1)"
[[ "$CUSTOMER_ID" =~ ^[0-9]+$ ]] || fail "Kundenkonto nicht angelegt: $CUSTOMER_ID"
echo "    Kunde $CUSTOMER_ID"
verify_link() { grep -oE "https?://[^[:space:]]*bs_verify=$CUSTOMER_ID[^[:space:]]*" "$BS_E2E_MAIL_LOG" 2>/dev/null | tail -n 1; }
poll "Bestätigungsmail" test -n "$(verify_link)" || fail "keine Bestätigungsmail in $BS_E2E_MAIL_LOG"
LINK="$(verify_link)"
CODE="$(curl -sS -o /dev/null -w '%{http_code}' "$LINK")"
[ "$CODE" = 302 ] || fail "Bestätigungslink lieferte HTTP $CODE statt 302"
[ -n "$(wpc user meta get "$CUSTOMER_ID" _bs_email_confirmed)" ] || fail "Adresse nach dem Link nicht bestätigt"
registered() { as_run >/dev/null; [ "$(wpc user meta get "$CUSTOMER_ID" _bs_registered 2>/dev/null)" = 1 ]; }
poll "Konto bei der App angemeldet (POST /accounts)" registered || fail "Konto nicht bei der App angemeldet"

step "3. Verknüpfungscode im Shop eingeben"
curl -sS -o /dev/null -c "$COOKIES" -b "$COOKIES" "$WP_URL/wp-login.php"
CODE="$(curl -sS -o /dev/null -w '%{http_code}' -c "$COOKIES" -b "$COOKIES" \
    --data-urlencode "log=$EMAIL" --data-urlencode "pwd=$PASSWORD" \
    --data-urlencode "testcookie=1" --data-urlencode "redirect_to=$WP_URL/" "$WP_URL/wp-login.php")"
[ "$CODE" = 302 ] || fail "Anmeldung im Shop lieferte HTTP $CODE statt 302"
ENDPOINT="$(wpc eval 'echo wc_get_account_endpoint_url("bootsschule");' | tail -n 1)"
curl -sS -c "$COOKIES" -b "$COOKIES" -o "$WORK/link-form.html" "$ENDPOINT"
NONCE="$(grep -oE 'name="_bs_link_nonce" value="[^"]+"' "$WORK/link-form.html" | sed -E 's/.*value="([^"]+)"/\1/' | head -n 1)"
[ -n "$NONCE" ] || { sed -n '1,80p' "$WORK/link-form.html"; fail "Formular „Bootsschule verknüpfen“ nicht gefunden ($ENDPOINT)"; }
CODE="$(curl -sS -o /dev/null -w '%{http_code}' -c "$COOKIES" -b "$COOKIES" \
    --data-urlencode "bs_link_code=$LINK_CODE" --data-urlencode "_bs_link_nonce=$NONCE" "$ENDPOINT")"
[ "$CODE" = 302 ] || fail "Verknüpfen lieferte HTTP $CODE statt 302"
(cd "$REPO" && php tests/connector-e2e/app-check.php account "$TENANT_ID" "$CUSTOMER_ID") || fail "Shopkonto in der App nicht mit der Testschule verknüpft"
curl -sS -c "$COOKIES" -b "$COOKIES" -o "$WORK/link-after.html" "$ENDPOINT"
grep -q 'Verknüpft mit: Connector-E2E Bootsschule' "$WORK/link-after.html" || fail "„Mein Konto“ zeigt die Verknüpfung nicht an"
echo "    ok: „Mein Konto“ zeigt „Verknüpft mit: Connector-E2E Bootsschule“"

step "4. Produkt mit Kurscode-Zuordnung"
read -r PRODUCT_ID COURSE < <(wpc eval-file "$HERE/wp/create-product.php" "$E2E_COURSE" | tail -n 1)
[[ "$PRODUCT_ID" =~ ^[0-9]+$ ]] || fail "Produkt nicht angelegt"
echo "    Produkt $PRODUCT_ID, Kurs $COURSE"

step "5. Bestellung (Überweisung, Zahlung verbucht, abgeschlossen) und Zustellung"
ORDER_ID="$(wpc eval-file "$HERE/wp/create-order.php" "$CUSTOMER_ID" "$PRODUCT_ID" "$E2E_QUANTITY" | tail -n 1)"
[[ "$ORDER_ID" =~ ^[0-9]+$ ]] || { ORDER_ID=""; fail "Bestellung nicht angelegt"; }
echo "    Bestellung $ORDER_ID über $E2E_QUANTITY x $COURSE"
delivered() { as_run >/dev/null; wpc eval-file "$HERE/wp/check-order.php" "$ORDER_ID" --quiet; }
poll "Zustellung im Shop vermerkt" delivered || fail "Bestellung $ORDER_ID nicht zugestellt"

step "6. Prüfung"
wpc eval-file "$HERE/wp/check-order.php" "$ORDER_ID" || fail "Bestellung $ORDER_ID nicht vollständig zugestellt"
(cd "$REPO" && php tests/connector-e2e/app-check.php batch "$TENANT_ID" "$ORDER_ID" "$E2E_QUANTITY" "$COURSE") \
    || fail "Codes der Testschule in der App fehlen oder stimmen nicht"

printf '\nBESTANDEN: Bestellung %s -> %s Codes %s im Codebestand der Testschule %s\n' "$ORDER_ID" "$E2E_QUANTITY" "$COURSE" "$TENANT_ID"
