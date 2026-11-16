#!/usr/bin/env bash
# Workflow-E2E-Test (WF-CUST1-08, -09): laufende Laravel-App (PostgreSQL mit beiden Rollen) ↔ echtes WordPress mit
# Workflow Captain, Workflow Captain Pro und dem BoatingSchool Workflow Connector. Ablauf:
#   1. ohne Workflow Captain Pro: keine Anmeldung, App-Zustellung bekommt 503 workflow_captain_pro_missing (F-18a)
#   2. Pro ohne Recht workflow.custom_connectors: runtime_not_allowed
#   3. mit Recht: Anmeldung, Freigabe, zwei Workflows, Testereignis, Statusseite
#   4. Shopkonto anmelden/verknüpfen, Codes liefern, Teil-Erstattung (Sperre) über die Commerce-API der App;
#      app:workflow-outbox stellt über das Netz zu; Ereignisse in Workflow Captain ohne Codes/Adressen
#   5. Workflow-Läufe: Befehl school_admin.notice an die App, dort unter RLS ausgeführt (workflow_command)
#   6. Timeout nach erfolgreichem Aufruf → erneute Zustellung ist ein Doppel
#   7. falsches Geheimnis → Wiederholungen bis „failed“ → Outbox-Replay mit derselben Event-ID
#   8. Schlüsselwechsel (zweites Paar), abgelaufener Zeitstempel, wiederholte Nonce, Diagnose
#
# Umgebung: WP_PATH, WP_URL, APP_URL, WF_SECRET, WF_OUT_SECRET, WF_OUT_SECRET_NEXT, COMMERCE_SECRET, APP_DB
# (Datenbank der laufenden App); optional WP_CLI_BIN (Standard: wp). Die App muss mit derselben Umgebung laufen
# (siehe app_env unten). Die WordPress-Site ist eine Wegwerf-Testsite mit eigener Datenbank; der Test schreibt
# wp-config.php-Konstanten, active_plugins und ein Must-use-Plugin (wp/bsw-e2e-site.php).
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
: "${WP_PATH:?}" "${WP_URL:?}" "${APP_URL:?}" "${WF_SECRET:?}" "${WF_OUT_SECRET:?}" "${WF_OUT_SECRET_NEXT:?}" "${COMMERCE_SECRET:?}" "${APP_DB:?}"
: "${WP_CLI_BIN:=wp}"
WP_URL="${WP_URL%/}"
APP_URL="${APP_URL%/}"

# Umgebung der App (gleich für den App-Server, app-driver.php und artisan).
app_env() {
    export APP_URL="$APP_URL" DB_DATABASE="$APP_DB" WORKFLOW_ENABLED=true WORKFLOW_DISPATCH=none MAIL_MAILER=log \
        WORKFLOW_CONNECTOR_URL="$WP_URL/wp-json/boatingschool-workflow/v1/events" \
        WORKFLOW_OUTBOUND_KEY_ID=app-workflow WORKFLOW_OUTBOUND_SECRET="$WF_OUT_SECRET" \
        WORKFLOW_KEY_ID=workflow WORKFLOW_SECRET="$WF_SECRET" \
        COMMERCE_SHOP_ID=wfe2e COMMERCE_SHOP_KEY_ID=shop COMMERCE_SHOP_SECRET="$COMMERCE_SECRET" COMMERCE_SHOP_URL= \
        E2E_APP_URL="$APP_URL"
}
app_env

# shellcheck disable=SC2086 # WP_CLI_BIN darf aus mehreren Wörtern bestehen
wpc() { $WP_CLI_BIN --path="$WP_PATH" "$@" 2> >(grep -v '^Warning: wp_update_plugins' >&2); }
drv() { (cd "$REPO" && php tests/workflow-e2e/app-driver.php "$@"); }
outbox() { (cd "$REPO" && php artisan app:workflow-outbox >/dev/null); }
jq_() { php -r '$d=json_decode(stream_get_contents(STDIN),true); echo eval("return ".$argv[1].";");' "$1"; }
FAILS=0
check() { if [ "$1" = 1 ]; then echo "  ok   $2"; else echo "  FAIL $2${3:+ – $3}"; FAILS=$((FAILS + 1)); fi; }
wp_phase() { wpc eval-file "$HERE/wp/checks.php" "$1" || FAILS=$((FAILS + 1)); }
plugins() { wpc option update active_plugins "[\"woocommerce/woocommerce.php\",\"workflow-captain/workflow-captain.php\"$1,\"boatingschool-workflow-connector/boatingschool-workflow-connector.php\"]" --format=json >/dev/null; }
flags() { wpc option update bsw_e2e "$1" --format=json >/dev/null; }
event_of() { drv events "$START" | jq_ "implode(',', array_column(array_filter(\$d, fn(\$e) => \$e['type'] === '$1'), '$2'))"; }

START="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
EMAIL="wf-e2e-$(date +%s)@example.test"
CUSTOMER="$((RANDOM * 10 + 7))"
ORDER="$(date +%s)"  # je Lauf neue Bestellnummern (die App merkt sich Positionen dauerhaft)

echo "==> 0. Konstanten in wp-config.php, Must-use-Plugin"
# Ohne die übliche Zeile „That's all, stop editing“ vor dem Laden von wp-settings.php einfügen.
ANCHOR=()
grep -q "That's all, stop editing" "$WP_PATH/wp-config.php" || ANCHOR=(--anchor="require_once ABSPATH . 'wp-settings.php';" --placement=before)
for kv in "BSW_CONNECTOR_APP_URL=$APP_URL" BSW_CONNECTOR_KEY_ID=workflow "BSW_CONNECTOR_SECRET=$WF_SECRET" \
    BSW_CONNECTOR_INBOUND_KEY_ID=app-workflow "BSW_CONNECTOR_INBOUND_SECRET=$WF_OUT_SECRET" \
    BSW_CONNECTOR_INBOUND_KEY_ID_NEXT=app-workflow-next "BSW_CONNECTOR_INBOUND_SECRET_NEXT=$WF_OUT_SECRET_NEXT"; do
    wpc config set "${kv%%=*}" "${kv#*=}" "${ANCHOR[@]}" --quiet || check 0 "wp-config.php: ${kv%%=*}"
done
wpc config set BSW_CONNECTOR_ALLOW_HTTP true --raw "${ANCHOR[@]}" --quiet
mkdir -p "$WP_PATH/wp-content/mu-plugins"
cp "$HERE/wp/bsw-e2e-site.php" "$WP_PATH/wp-content/mu-plugins/bsw-e2e-site.php"
# Danach den Ersatz wieder entfernen, damit andere Tests derselben Site nur echte Lizenzen sehen.
trap 'rm -f "$WP_PATH/wp-content/mu-plugins/bsw-e2e-site.php"; wpc option delete bsw_e2e >/dev/null 2>&1' EXIT
curl -fs -o /dev/null --max-time 5 "$APP_URL/up" && check 1 "App erreichbar" || check 0 "App erreichbar" "$APP_URL/up"
curl -fs -o /dev/null --max-time 5 "$WP_URL/wp-login.php" && check 1 "WordPress erreichbar" || check 0 "WordPress erreichbar"

echo "==> 1. Ohne Workflow Captain Pro (F-18a)"
plugins ''
flags '{"right":true,"license":true}'
wp_phase no_pro
SETUP="$(drv setup "$EMAIL")"
TENANT="$(echo "$SETUP" | jq_ '$d["tenant_id"]')"
LINK="$(echo "$SETUP" | jq_ '$d["link_code"]')"
R="$(drv commerce POST /accounts "{\"shop_customer_id\":\"$CUSTOMER\",\"email\":\"$EMAIL\",\"company_name\":\"Workflow-E2E Bootsschule\",\"first_name\":\"Pia\",\"last_name\":\"Meer\",\"vat_id\":\"DE123456789\"}")"
check "$(echo "$R" | jq_ '(int) in_array($d["status"], [200, 201], true)')" "Shopkonto über die Commerce-API angemeldet" "$R"
outbox
REG="$(event_of shop_account.registered id)"
check "$(event_of shop_account.registered last_error | grep -c '^http_503:workflow_captain_pro_missing$')" "App-Zustellung ohne Pro: 503 workflow_captain_pro_missing, Ereignis bleibt zur Wiederholung" "$(event_of shop_account.registered last_error)"

echo "==> 2. Workflow Captain Pro ohne Recht"
plugins ',"workflow-captain-pro/workflow-captain-pro.php"'
flags '{"right":false,"license":true}'
wp_phase no_right

echo "==> 3. Mit Recht: Anmeldung, Freigabe, Workflows, Testereignis, Statusseite"
flags '{"right":true,"license":true}'
wp_phase setup

echo "==> 4. Ereignisse der App über das Netz"
drv due "$REG" >/dev/null
R="$(drv commerce POST "/accounts/$CUSTOMER/link" "{\"link_code\":\"$LINK\"}")"
check "$(echo "$R" | jq_ '(int) ($d["body"]["data"]["status"] === "linked")')" "Shopkonto mit der Testschule verknüpft" "$R"
R="$(drv commerce POST /orders/lines "{\"shop_customer_id\":\"$CUSTOMER\",\"order_id\":\"$ORDER\",\"order_line_id\":\"1\",\"kind\":\"course\",\"course_code\":\"SRC\",\"quantity\":2}")"
BATCH="$(echo "$R" | jq_ '$d["body"]["data"]["batch_id"] ?? ""')"
check "$([ -n "$BATCH" ] && echo 1 || echo 0)" "Codes geliefert (Batch)" "$R"
R="$(drv commerce POST "/batches/$BATCH/void" '{"reason":"Rückerstattung an Pia Meer","quantity":1}' "refund-$ORDER-1")"
check "$(echo "$R" | jq_ '(int) ($d["status"] === 200)')" "Teil-Erstattung: Sperre über den WooCommerce-Weg" "$R"
outbox
EV="$(drv events "$START")"
check "$(echo "$EV" | jq_ '(int) (count($d) >= 4 && count(array_filter($d, fn($e) => $e["status"] !== "done")) === 0)')" "alle Ereignisse zugestellt (done)" "$EV"
CODES="$(drv codes "$BATCH")"
FORBIDDEN="$(php -r '$c=json_decode($argv[1],true)?:[]; echo json_encode(array_merge($c,[$argv[2],"Pia","Meer","DE123456789",$argv[3]]));' "$CODES" "$EMAIL" "$LINK")"
BSW_E2E_TYPES=shop_account.registered,shop_account.linked,code_batch.delivered,code_batch.revoked BSW_E2E_FORBIDDEN="$FORBIDDEN" wp_phase captured

echo "==> 5. Workflow-Läufe → Befehl an die App (RLS je Schule)"
wp_phase work
CMDS="$(drv commands "$TENANT")"
check "$(echo "$CMDS" | jq_ '(int) (count(array_filter($d, fn($c) => $c["action"] === "school_admin.notice" && $c["status"] === "done" && ($c["result"]["recipients"] ?? 0) >= 1)) >= 2)')" "App: school_admin.notice für die Testschule ausgeführt (Empfänger aus der Schule)" "$CMDS"
check "$(echo "$CMDS" | jq_ '(int) ! str_contains(json_encode($d), "@")')" "Befehlsergebnis ohne Adressen"

echo "==> 6. Timeout nach erfolgreichem Aufruf"
flags '{"right":true,"license":true,"slow":true}'
R="$(drv commerce POST "/accounts/$CUSTOMER/deactivate")"
outbox
DEACT="$(event_of shop_account.deactivated id)"
check "$(event_of shop_account.deactivated last_error | grep -c '^timeout$')" "Zustellung lief in den Timeout (Plugin hat trotzdem erfasst)" "$(event_of shop_account.deactivated status):$(event_of shop_account.deactivated last_error)"
flags '{"right":true,"license":true}'
drv due "$DEACT" >/dev/null
outbox
check "$(event_of shop_account.deactivated status | grep -c '^done$')" "erneute Zustellung mit derselben Event-ID: Doppel, gilt als zugestellt"

echo "==> 7. Falsches Geheimnis → failed → Outbox-Replay"
R="$(drv commerce POST /orders/lines "{\"shop_customer_id\":\"$CUSTOMER\",\"order_id\":\"$((ORDER + 1))\",\"order_line_id\":\"1\",\"kind\":\"course\",\"course_code\":\"SRC\",\"quantity\":1}")"
check "$(echo "$R" | jq_ '(int) ($d["status"] === 409 || $d["status"] === 422 || $d["status"] === 403 || $d["status"] === 201)')" "weitere Lieferung angefragt (Konto deaktiviert: App entscheidet)" "$R"
# Ein Ereignis, das sicher entsteht: zweiter Storno-Schritt am ersten Batch.
R="$(drv commerce POST "/batches/$BATCH/void" '{"reason":"Rest","quantity":1}' "refund-$ORDER-2")"
NEXT_ID="$(drv events "$START" | jq_ 'implode(",", array_column(array_filter($d, fn($e) => $e["status"] === "pending"), "id"))')"
check "$([ -n "$NEXT_ID" ] && [[ "$NEXT_ID" != *,* ]] && echo 1 || echo 0)" "ein neues Ereignis wartet" "$NEXT_ID"
for _ in 1 2 3 4 5 6 7 8 9; do
    drv due "$NEXT_ID" >/dev/null
    WORKFLOW_OUTBOUND_SECRET=falsches-geheimnis-0123456789 outbox
done
check "$(drv events "$START" | jq_ "(int) (array_values(array_filter(\$d, fn(\$e) => \$e['id'] === '$NEXT_ID'))[0]['status'] === 'failed')")" "nach 9 Versuchen mit falscher Signatur: failed (401 bad_signature)" "$(drv events "$START" | jq_ "json_encode(array_values(array_filter(\$d, fn(\$e) => \$e['id'] === '$NEXT_ID')))")"
(cd "$REPO" && php artisan app:workflow-outbox --requeue="$NEXT_ID" >/dev/null)
check "$(drv events "$START" | jq_ "(int) (array_values(array_filter(\$d, fn(\$e) => \$e['id'] === '$NEXT_ID'))[0]['status'] === 'done')")" "Outbox-Replay mit derselben Event-ID: zugestellt"

echo "==> 8. Schlüsselwechsel, Zeitstempel, Nonce, Diagnose"
R="$(WORKFLOW_OUTBOUND_KEY_ID=app-workflow-next WORKFLOW_OUTBOUND_SECRET="$WF_OUT_SECRET_NEXT" drv raw 0 0)"
check "$(echo "$R" | jq_ '(int) ($d[0]["status"] === 200)')" "zweites Schlüsselpaar (Wechsel) angenommen" "$R"
R="$(drv raw -600 0)"
check "$(echo "$R" | jq_ '(int) ($d[0]["status"] === 401 && $d[0]["code"] === "stale_timestamp")')" "abgelaufener Zeitstempel: 401 stale_timestamp" "$R"
R="$(drv raw 0 1)"
check "$(echo "$R" | jq_ '(int) ($d[0]["status"] === 200 && $d[1]["status"] === 409 && $d[1]["code"] === "replay")')" "wiederholte Nonce: 409 replay" "$R"
R="$(drv raw 0 0 falsches-geheimnis-0123456789)"
check "$(echo "$R" | jq_ '(int) ($d[0]["status"] === 401 && $d[0]["code"] === "bad_signature")')" "falsche Signatur: 401 bad_signature" "$R"
BSW_E2E_EXPECT='{"duplicates_min":1,"refused":["workflow_captain_pro_missing","bad_signature","stale_timestamp","replay"]}' wp_phase diagnostics

echo
[ "$FAILS" -eq 0 ] && echo "BESTANDEN: Workflow-E2E App ↔ WordPress ↔ Workflow Captain" || echo "FEHLER: $FAILS Prüfung(en)"
exit $((FAILS > 0))
