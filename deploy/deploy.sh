#!/usr/bin/env bash
# Update auf dem Server (im App-Verzeichnis ausführen, als Deploy-Benutzer,
# nicht als root):
#
#   ./deploy/deploy.sh [branch] [commit] [--with-content] [--build-dir DIR]
#
# Mit [commit] wird genau dieser (grün getestete) Stand ausgerollt.
#
# Ablauf: Wartungsmodus → Sicherung → Code holen → Abhängigkeiten → Assets →
# Migrationen (Owner-Rolle) → Inhalte (Seeder, nur bei Bedarf) → Storage-Link →
# Caches → Wartungsmodus aus → Go-live-Check. Schlägt ein Schritt fehl, bleibt
# die Seite im Wartungsmodus (sicherer als halb aktualisierter Code) -- Fehler
# beheben und das Skript erneut starten, oder mit `php artisan up` zurück.
#
# Assets: Liegt in BUILD_DIR (Umgebungsvariable oder --build-dir) ein fertiger
# Vite-Build (Inhalt von public/build, z. B. vom Deploy-Workflow), wird er nur
# kopiert -- sonst wird wie bisher auf dem Server gebaut. Enthält BUILD_DIR
# eine build-sha.txt, muss sie zum ausgerollten Commit passen.
#
# Inhalte: `db:seed` (Fragenkatalog usw.) läuft nur, wenn sich seit dem zuletzt
# ausgerollten Commit (storage/app/deployed_sha) etwas unter database/seeders
# oder database/data geändert hat, beim ersten Deploy ohne gespeicherten Stand
# oder mit --with-content.
set -euo pipefail

BRANCH=""
TARGET=""
WITH_CONTENT=0
BUILD_DIR="${BUILD_DIR:-}"
while [ $# -gt 0 ]; do
    case "$1" in
        --with-content) WITH_CONTENT=1 ;;
        --build-dir) BUILD_DIR="${2:?--build-dir braucht ein Verzeichnis}"; shift ;;
        --build-dir=*) BUILD_DIR="${1#--build-dir=}" ;;
        -*) echo "Unbekannte Option: $1" >&2; exit 2 ;;
        *)
            if [ -z "$BRANCH" ]; then BRANCH="$1"
            elif [ -z "$TARGET" ]; then TARGET="$1"
            else echo "Zu viele Argumente: $1" >&2; exit 2
            fi ;;
    esac
    shift
done
BRANCH="${BRANCH:-main}"

cd "$(dirname "$0")/.."

STATE_FILE="storage/app/deployed_sha"
CONTENT_PATHS=(database/seeders database/data)

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$1"; }
info() { printf '    %s\n' "$1"; }
trap 'printf "\n\033[1;31mDeploy abgebrochen (Zeile %s). Die Seite bleibt im Wartungsmodus.\033[0m\n" "$LINENO"' ERR

LAST_SHA="$(cat "$STATE_FILE" 2>/dev/null || true)"

step "Wartungsmodus an"
php artisan down --retry=15 --refresh=15 || true

step "Sicherung vor dem Update"
# Migrationen sind nur vorwärts gedacht: vorher immer ein frischer Dump.
php artisan app:backup

step "Code holen ($BRANCH${TARGET:+ @ $TARGET})"
git fetch --quiet origin "$BRANCH"
git checkout --quiet "$BRANCH"
git merge --ff-only --quiet "${TARGET:-origin/$BRANCH}"
NEW_SHA="$(git rev-parse HEAD)"

step "PHP-Abhängigkeiten"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress

step "Assets"
use_prebuilt=0
if [ -n "$BUILD_DIR" ]; then
    if [ ! -f "$BUILD_DIR/manifest.json" ]; then
        info "BUILD_DIR=$BUILD_DIR enthält keinen Vite-Build (manifest.json fehlt) -- baue auf dem Server."
    elif [ -f "$BUILD_DIR/build-sha.txt" ] && [ "$(tr -d '[:space:]' < "$BUILD_DIR/build-sha.txt")" != "$NEW_SHA" ]; then
        info "Build in $BUILD_DIR gehört zu einem anderen Commit -- baue auf dem Server."
    else
        use_prebuilt=1
    fi
fi
if [ "$use_prebuilt" = 1 ]; then
    info "Vorgebaute Assets aus $BUILD_DIR übernehmen (kein Build auf dem Server)."
    rm -rf public/build.new public/build.old
    mkdir -p public/build.new
    cp -R "$BUILD_DIR"/. public/build.new/
    rm -f public/build.new/build-sha.txt
    if [ -d public/build ]; then mv public/build public/build.old; fi
    mv public/build.new public/build
    rm -rf public/build.old
else
    info "Assets auf dem Server bauen (npm ci && npm run build)."
    npm ci --no-audit --no-fund --silent
    npm run build --silent
fi

step "Migrationen (Owner-Rolle)"
php artisan migrate --database=pgsql_admin --force

# Katalog-Updates (Fragen, Erklärungen, Navigation, Praxistrainer) kommen als
# Seeder mit; die Seeder sind idempotent und lassen Superadmin-Korrekturen
# stehen. Sie laufen aber mehrere Minuten -- deshalb nur bei Bedarf.
step "Inhalte (Seeder)"
seed=0
if [ "$WITH_CONTENT" = 1 ]; then
    info "Seeder laufen: per --with-content angefordert."
    seed=1
elif [ -z "$LAST_SHA" ]; then
    info "Seeder laufen: kein zuletzt ausgerollter Stand bekannt ($STATE_FILE fehlt)."
    seed=1
elif ! git cat-file -e "${LAST_SHA}^{commit}" 2>/dev/null; then
    info "Seeder laufen: zuletzt ausgerollter Stand $LAST_SHA ist im Repository unbekannt."
    seed=1
elif ! git diff --quiet "$LAST_SHA" "$NEW_SHA" -- "${CONTENT_PATHS[@]}"; then
    info "Seeder laufen: Änderungen unter ${CONTENT_PATHS[*]} seit ${LAST_SHA:0:12}:"
    git diff --stat "$LAST_SHA" "$NEW_SHA" -- "${CONTENT_PATHS[@]}" | tail -n 15 | sed 's/^/      /'
    seed=1
else
    info "Seeder übersprungen: keine Änderungen unter ${CONTENT_PATHS[*]} seit ${LAST_SHA:0:12} (erzwingen mit --with-content)."
fi
if [ "$seed" = 1 ]; then
    php artisan db:seed --database=pgsql_admin --force
fi
# Erst nach erfolgreichen Migrationen/Seedern merken -- ein vorher
# abgebrochener Deploy spielt die Inhalte beim nächsten Lauf erneut ein.
mkdir -p "$(dirname "$STATE_FILE")"
printf '%s\n' "$NEW_SHA" > "$STATE_FILE"

step "Storage-Link"
[ -e public/storage ] || php artisan storage:link

step "Caches"
php artisan optimize
php artisan queue:restart
# Kursliste der App vorwärmen (sonst ist der erste Aufruf nach dem Deploy langsam).
php artisan app:warm-course-cache || true
# Opcache leeren (bei opcache.validate_timestamps=0 sonst alter Code):
# PHP_FPM_SERVICE=php8.3-fpm setzen, wenn der Deploy-Benutzer reloaden darf.
if [ -n "${PHP_FPM_SERVICE:-}" ]; then sudo systemctl reload "$PHP_FPM_SERVICE"; fi

step "Wartungsmodus aus"
php artisan up

step "Go-live-Check"
php artisan app:check-production || printf '\n\033[1;33mHinweis: der Go-live-Check meldet offene Punkte (siehe oben).\033[0m\n'

printf '\n\033[1;32mDeploy fertig: %s\033[0m\n' "$(git log -1 --format='%h %s')"
