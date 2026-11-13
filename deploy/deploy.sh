#!/usr/bin/env bash
# Update auf dem Server (im App-Verzeichnis ausführen, als Deploy-Benutzer,
# nicht als root):
#
#   ./deploy/deploy.sh [branch] [commit] [--with-content] [--build-dir DIR]
#   ./deploy/deploy.sh --rollback          # nur DEPLOY_MODE=releases
#
# Mit [commit] wird genau dieser (grün getestete) Stand ausgerollt.
#
# Zwei Betriebsarten (DEPLOY_MODE):
#
# inplace (Standard, bestehende Server): ein Git-Checkout, der aktualisiert
#   wird. Ablauf: Wartungsmodus → Sicherung → Code holen → Abhängigkeiten →
#   Assets → Migrationen (Owner-Rolle) → Inhalte (Seeder, nur bei Bedarf) →
#   Storage-Link → Caches → Wartungsmodus aus → Go-live-Check. Schlägt ein
#   Schritt fehl, bleibt die Seite im Wartungsmodus (sicherer als halb
#   aktualisierter Code) -- Fehler beheben und das Skript erneut starten,
#   oder mit `php artisan up` zurück.
#
# releases (optional): jedes Update in einem eigenen Ordner
#   <basis>/releases/<Zeitstempel>-<sha>, gemeinsame Daten in <basis>/shared
#   (storage/ und .env als Symlinks), <basis>/current zeigt auf den aktiven
#   Stand. Abhängigkeiten, Assets und Caches entstehen, während der alte
#   Stand weiterläuft; Wartungsmodus nur für Sicherung, Migrationen und das
#   atomare Umschalten von `current`. Die letzten KEEP_RELEASES (Standard 5)
#   Stände bleiben liegen; `--rollback` schaltet auf den vorherigen zurück
#   (nur Code -- Migrationen bleiben in der Datenbank!). Erkannt wird der
#   Modus automatisch, wenn das Skript aus <basis>/releases/<…>/ läuft
#   (z. B. über <basis>/current/deploy/deploy.sh); sonst DEPLOY_MODE=releases
#   und DEPLOY_BASE=<basis> setzen (Umstieg, siehe deploy/README.md).
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
set -Eeuo pipefail

BRANCH=""
TARGET=""
WITH_CONTENT=0
ROLLBACK=0
BUILD_DIR="${BUILD_DIR:-}"
while [ $# -gt 0 ]; do
    case "$1" in
        --with-content) WITH_CONTENT=1 ;;
        --rollback) ROLLBACK=1 ;;
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

# Physischer Pfad (current ist im Modus releases ein Symlink auf den Release-Ordner).
APP_DIR="$(cd -P "$(dirname "$0")/.." && pwd -P)"
cd "$APP_DIR"

STATE_REL="storage/app/deployed_sha"
CONTENT_PATHS=(database/seeders database/data)
KEEP_RELEASES="${KEEP_RELEASES:-5}"
GIT_ARGS=()

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$1"; }
info() { printf '    %s\n' "$1"; }
warn() { printf '\n\033[1;33m%s\033[0m\n' "$1"; }
die() { printf '\n\033[1;31m%s\033[0m\n' "$1" >&2; exit 1; }

if [ -z "${DEPLOY_MODE:-}" ]; then
    if [ "$(basename "$(dirname "$APP_DIR")")" = "releases" ]; then DEPLOY_MODE=releases; else DEPLOY_MODE=inplace; fi
fi
case "$DEPLOY_MODE" in
    inplace|releases) ;;
    *) die "Unbekannter DEPLOY_MODE: $DEPLOY_MODE (inplace oder releases)" ;;
esac
if [ "$ROLLBACK" = 1 ] && [ "$DEPLOY_MODE" != releases ]; then
    die "--rollback gibt es nur mit DEPLOY_MODE=releases (im Modus inplace: Fehler beheben und neu ausrollen, im Notfall Sicherung zurückspielen)."
fi

# Vite-Build aus BUILD_DIR übernehmen oder bauen ($1 = Code-Ordner, $2 = Commit).
assets() {
    local dir="$1" sha="$2" use_prebuilt=0
    if [ -n "$BUILD_DIR" ]; then
        if [ ! -f "$BUILD_DIR/manifest.json" ]; then
            info "BUILD_DIR=$BUILD_DIR enthält keinen Vite-Build (manifest.json fehlt) -- baue auf dem Server."
        elif [ -f "$BUILD_DIR/build-sha.txt" ] && [ "$(tr -d '[:space:]' < "$BUILD_DIR/build-sha.txt")" != "$sha" ]; then
            info "Build in $BUILD_DIR gehört zu einem anderen Commit -- baue auf dem Server."
        else
            use_prebuilt=1
        fi
    fi
    if [ "$use_prebuilt" = 1 ]; then
        info "Vorgebaute Assets aus $BUILD_DIR übernehmen (kein Build auf dem Server)."
        rm -rf "$dir/public/build.new" "$dir/public/build.old"
        mkdir -p "$dir/public/build.new"
        cp -R "$BUILD_DIR"/. "$dir/public/build.new/"
        rm -f "$dir/public/build.new/build-sha.txt"
        if [ -d "$dir/public/build" ]; then mv "$dir/public/build" "$dir/public/build.old"; fi
        mv "$dir/public/build.new" "$dir/public/build"
        rm -rf "$dir/public/build.old"
    else
        info "Assets auf dem Server bauen (npm ci && npm run build)."
        (cd "$dir" && npm ci --no-audit --no-fund --silent && npm run build --silent)
    fi
}

# Seeder nötig? ($1 = zuletzt ausgerollt, $2 = neu; Repository über GIT_ARGS)
seed_needed() {
    local last="$1" new="$2"
    if [ "$WITH_CONTENT" = 1 ]; then
        info "Seeder laufen: per --with-content angefordert."
    elif [ -z "$last" ]; then
        info "Seeder laufen: kein zuletzt ausgerollter Stand bekannt ($STATE_REL fehlt)."
    elif ! git ${GIT_ARGS[@]+"${GIT_ARGS[@]}"} cat-file -e "${last}^{commit}" 2>/dev/null; then
        info "Seeder laufen: zuletzt ausgerollter Stand $last ist im Repository unbekannt."
    elif ! git ${GIT_ARGS[@]+"${GIT_ARGS[@]}"} diff --quiet "$last" "$new" -- "${CONTENT_PATHS[@]}"; then
        info "Seeder laufen: Änderungen unter ${CONTENT_PATHS[*]} seit ${last:0:12}:"
        git ${GIT_ARGS[@]+"${GIT_ARGS[@]}"} diff --stat "$last" "$new" -- "${CONTENT_PATHS[@]}" | tail -n 15 | sed 's/^/      /'
    else
        info "Seeder übersprungen: keine Änderungen unter ${CONTENT_PATHS[*]} seit ${last:0:12} (erzwingen mit --with-content)."
        return 1
    fi
    return 0
}

reload_fpm() {
    # Opcache leeren (bei opcache.validate_timestamps=0 sonst alter Code; im
    # Modus releases zusätzlich der Realpath-Cache des alten Ordners):
    # PHP_FPM_SERVICE=php8.3-fpm setzen, wenn der Deploy-Benutzer reloaden darf.
    if [ -n "${PHP_FPM_SERVICE:-}" ]; then sudo systemctl reload "$PHP_FPM_SERVICE"; fi
}

# =============================================================================
# Modus inplace (Standard): ein Checkout, der aktualisiert wird.
# =============================================================================
deploy_inplace() {
    trap 'printf "\n\033[1;31mDeploy abgebrochen (Zeile %s). Die Seite bleibt im Wartungsmodus.\033[0m\n" "$LINENO"' ERR

    local last_sha new_sha
    last_sha="$(cat "$STATE_REL" 2>/dev/null || true)"

    step "Wartungsmodus an"
    php artisan down --retry=15 --refresh=15 || true

    step "Sicherung vor dem Update"
    # Migrationen sind nur vorwärts gedacht: vorher immer ein frischer Dump.
    php artisan app:backup

    step "Code holen ($BRANCH${TARGET:+ @ $TARGET})"
    git fetch --quiet origin "$BRANCH"
    git checkout --quiet "$BRANCH"
    git merge --ff-only --quiet "${TARGET:-origin/$BRANCH}"
    new_sha="$(git rev-parse HEAD)"

    step "PHP-Abhängigkeiten"
    composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress

    step "Assets"
    assets "$APP_DIR" "$new_sha"

    step "Migrationen (Owner-Rolle)"
    php artisan migrate --database=pgsql_admin --force

    # Katalog-Updates (Fragen, Erklärungen, Navigation, Praxistrainer) kommen als
    # Seeder mit; die Seeder sind idempotent und lassen Superadmin-Korrekturen
    # stehen. Sie laufen aber mehrere Minuten -- deshalb nur bei Bedarf.
    step "Inhalte (Seeder)"
    if seed_needed "$last_sha" "$new_sha"; then
        php artisan db:seed --database=pgsql_admin --force
    fi
    # Erst nach erfolgreichen Migrationen/Seedern merken -- ein vorher
    # abgebrochener Deploy spielt die Inhalte beim nächsten Lauf erneut ein.
    mkdir -p "$(dirname "$STATE_REL")"
    printf '%s\n' "$new_sha" > "$STATE_REL"

    step "Storage-Link"
    [ -e public/storage ] || php artisan storage:link

    step "Caches"
    php artisan optimize
    # Queue-Worker (Sammel-Mails) laden den neuen Code.
    php artisan queue:restart
    # Kursliste der App vorwärmen (sonst ist der erste Aufruf nach dem Deploy langsam).
    php artisan app:warm-course-cache || true
    reload_fpm

    step "Wartungsmodus aus"
    php artisan up

    step "Go-live-Check"
    php artisan app:check-production || warn 'Hinweis: der Go-live-Check meldet offene Punkte (siehe oben).'

    printf '\n\033[1;32mDeploy fertig: %s\033[0m\n' "$(git log -1 --format='%h %s')"
}

# =============================================================================
# Modus releases (optional)
# =============================================================================
release_base() {
    if [ -n "${DEPLOY_BASE:-}" ]; then
        mkdir -p "$DEPLOY_BASE"
        (cd -P "$DEPLOY_BASE" && pwd -P)
    elif [ "$(basename "$(dirname "$APP_DIR")")" = "releases" ]; then
        dirname "$(dirname "$APP_DIR")"
    else
        die "DEPLOY_MODE=releases: DEPLOY_BASE=<basis> setzen (Ordner mit releases/, shared/ und current) oder das Skript über <basis>/current/deploy/deploy.sh starten."
    fi
}

# Atomar umschalten: neuer Symlink daneben, dann per rename(2) ersetzen.
switch_current() {
    local base="$1" target="$2"
    ln -sfn "$target" "$base/current.new"
    mv -Tf "$base/current.new" "$base/current"
}

prune_releases() {
    local base="$1" keep_path="$2" active count=0
    active="$(readlink -f "$base/current" 2>/dev/null || true)"
    # Neueste zuerst (Ordnernamen beginnen mit dem Zeitstempel).
    while IFS= read -r dir; do
        [ -n "$dir" ] || continue
        count=$((count + 1))
        if [ "$count" -le "$KEEP_RELEASES" ] || [ "$dir" = "$active" ] || [ "$dir" = "$keep_path" ]; then
            continue
        fi
        info "Entferne $(basename "$dir")"
        rm -rf "$dir"
    done < <(find "$base/releases" -mindepth 1 -maxdepth 1 -type d | sort -r)
}

deploy_releases() {
    local base repo shared rel="" new_sha last_sha previous url
    base="$(release_base)"
    repo="$base/repo.git"
    shared="$base/shared"
    DOWN=0
    trap 'printf "\n\033[1;31mDeploy abgebrochen (Zeile %s). Der bisherige Stand läuft unverändert weiter.\033[0m\n" "$LINENO"' ERR

    mkdir -p "$base/releases"
    [ -f "$shared/.env" ] || die "$shared/.env fehlt (beim Umstieg aus dem bisherigen App-Ordner kopieren, siehe deploy/README.md)."
    [ -d "$shared/storage" ] || die "$shared/storage fehlt (beim Umstieg aus dem bisherigen App-Ordner übernehmen, siehe deploy/README.md)."
    mkdir -p "$shared/storage/app/public" "$shared/storage/framework/cache" "$shared/storage/framework/sessions" \
        "$shared/storage/framework/views" "$shared/storage/logs"

    # Nie zwei Deploys gleichzeitig in denselben Basisordner.
    if command -v flock >/dev/null 2>&1; then
        exec 9>"$base/.deploy.lock"
        flock -n 9 || die "Ein anderer Deploy läuft gerade ($base/.deploy.lock)."
    fi

    step "Code holen ($BRANCH${TARGET:+ @ $TARGET})"
    if [ ! -d "$repo" ]; then
        url="${DEPLOY_REPO_URL:-$(git -C "$APP_DIR" remote get-url origin 2>/dev/null || true)}"
        [ -n "$url" ] || die "Kein Repository bekannt: DEPLOY_REPO_URL setzen."
        info "Lege $repo an (bare clone von $url)."
        git clone --quiet --bare "$url" "$repo"
    fi
    GIT_ARGS=(--git-dir="$repo")
    git "${GIT_ARGS[@]}" fetch --quiet origin "+refs/heads/$BRANCH:refs/heads/$BRANCH"
    new_sha="$(git "${GIT_ARGS[@]}" rev-parse --verify --quiet "${TARGET:-refs/heads/$BRANCH}^{commit}")" \
        || die "Commit ${TARGET:-$BRANCH} nicht gefunden."
    # Wie --ff-only im Modus inplace: nur Stände, die auf dem Branch liegen.
    git "${GIT_ARGS[@]}" merge-base --is-ancestor "$new_sha" "refs/heads/$BRANCH" \
        || die "Commit $new_sha liegt nicht auf $BRANCH."

    rel="$base/releases/$(date +%Y%m%d%H%M%S)-${new_sha:0:12}"
    [ ! -e "$rel" ] || die "$rel existiert bereits."
    # Abbruch vor dem Umschalten: der alte Stand läuft weiter, der halbe Ordner wird entfernt.
    trap 'rc=$?
        if [ "$DOWN" = 1 ]; then
            printf "\n\033[1;31mDeploy abgebrochen (Zeile %s). Die Seite bleibt im Wartungsmodus, current zeigt weiter auf den alten Stand.\nFehler beheben und erneut starten -- oder ohne Update zurück: php artisan up\033[0m\n" "$LINENO"
        else
            printf "\n\033[1;31mDeploy abgebrochen (Zeile %s). Der bisherige Stand läuft unverändert weiter.\033[0m\n" "$LINENO"
        fi
        cd "$base" && rm -rf "$rel"
        exit "$rc"' ERR

    mkdir -p "$rel"
    git "${GIT_ARGS[@]}" archive "$new_sha" | tar -x -C "$rel"
    printf '%s\n' "$new_sha" > "$rel/REVISION"
    info "Neuer Stand: $rel"

    # Gemeinsame Daten: storage/ und .env gelten für alle Stände.
    rm -rf "$rel/storage"
    ln -s "$shared/storage" "$rel/storage"
    ln -s "$shared/.env" "$rel/.env"
    mkdir -p "$rel/bootstrap/cache"
    previous="$(readlink -f "$base/current" 2>/dev/null || true)"
    cd "$rel"

    step "PHP-Abhängigkeiten"
    # vendor/ des laufenden Stands als Ausgangspunkt (spart Downloads).
    if [ -n "$previous" ] && [ -d "$previous/vendor" ]; then cp -a "$previous/vendor" "$rel/vendor"; fi
    composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress

    step "Assets"
    assets "$rel" "$new_sha"

    step "Storage-Link"
    php artisan storage:link

    step "Caches (neuer Stand, der bisherige läuft weiter)"
    php artisan optimize

    last_sha="$(cat "$shared/$STATE_REL" 2>/dev/null || true)"

    step "Wartungsmodus an"
    php artisan down --retry=15 --refresh=15 || true
    DOWN=1

    step "Sicherung vor dem Update"
    # Migrationen sind nur vorwärts gedacht: vorher immer ein frischer Dump.
    php artisan app:backup

    step "Migrationen (Owner-Rolle, aus dem neuen Stand)"
    php artisan migrate --database=pgsql_admin --force

    step "Inhalte (Seeder)"
    if seed_needed "$last_sha" "$new_sha"; then
        php artisan db:seed --database=pgsql_admin --force
    fi

    step "Umschalten: current -> $(basename "$rel")"
    switch_current "$base" "$rel"
    trap 'printf "\n\033[1;31mDeploy abgebrochen (Zeile %s) nach dem Umschalten auf den neuen Stand.\033[0m\n" "$LINENO"' ERR
    mkdir -p "$(dirname "$shared/$STATE_REL")"
    printf '%s\n' "$new_sha" > "$shared/$STATE_REL"
    # Queue-Worker (Sammel-Mails) beenden sich und starten über
    # <basis>/current/artisan mit dem neuen Code (supervisor).
    php artisan queue:restart
    php artisan app:warm-course-cache || true
    reload_fpm

    step "Wartungsmodus aus"
    php artisan up
    DOWN=0

    step "Alte Stände aufräumen (behalten: $KEEP_RELEASES)"
    prune_releases "$base" "$rel"

    step "Go-live-Check"
    php artisan app:check-production || warn 'Hinweis: der Go-live-Check meldet offene Punkte (siehe oben).'

    printf '\n\033[1;32mDeploy fertig: %s\n%s\033[0m\n' "$(git "${GIT_ARGS[@]}" log -1 --format='%h %s' "$new_sha")" "current -> $rel"
}

rollback_releases() {
    local base active previous sha
    base="$(release_base)"
    active="$(readlink -f "$base/current" 2>/dev/null || true)"
    if [ -z "$active" ] || [ ! -d "$active" ]; then die "$base/current zeigt auf keinen Stand."; fi
    previous="$(find "$base/releases" -mindepth 1 -maxdepth 1 -type d | sort | awk -v cur="$active" '$0 == cur { print prev; exit } { prev = $0 }')"
    [ -n "$previous" ] || die "Kein älterer Stand vor $(basename "$active") vorhanden."

    warn "Achtung: Der Rollback betrifft nur den Code. Migrationen des neueren Stands bleiben in der Datenbank -- der ältere Code muss mit diesem Schema zurechtkommen. Im Zweifel stattdessen die Sicherung zurückspielen (deploy/README.md, „Backup zurückspielen“)."
    step "Rollback: current -> $(basename "$previous") (bisher $(basename "$active"))"
    cd "$previous"
    # Caches des älteren Stands mit der aktuellen .env neu aufbauen.
    php artisan optimize
    switch_current "$base" "$previous"
    if [ -f "$previous/REVISION" ]; then
        sha="$(tr -d '[:space:]' < "$previous/REVISION")"
        mkdir -p "$(dirname "$base/shared/$STATE_REL")"
        printf '%s\n' "$sha" > "$base/shared/$STATE_REL"
    fi
    php artisan queue:restart
    reload_fpm
    php artisan up || true
    printf '\n\033[1;32mRollback fertig: current -> %s\033[0m\n' "$previous"
    info "Der zurückgenommene Stand bleibt unter $active liegen (wird beim nächsten Deploy ggf. aufgeräumt)."
}

if [ "$DEPLOY_MODE" = releases ]; then
    if [ "$ROLLBACK" = 1 ]; then rollback_releases; else deploy_releases; fi
else
    deploy_inplace
fi
