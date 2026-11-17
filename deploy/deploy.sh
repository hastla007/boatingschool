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
#   wird. Ablauf: Sperre (nur ein Deploy zur Zeit) → Code holen, ohne
#   umzuschalten (nur Fast-forward) → Abhängigkeiten und Assets in einem
#   Vorbereitungsordner (.deploy/staging) bauen, die Seite läuft dabei weiter
#   → Wartungsmodus → Sicherung → umschalten (Code, vendor/, public/build) →
#   Migrationen (Owner-Rolle) → Inhalte (Seeder, nur bei Bedarf) →
#   Storage-Link → Caches → Wartungsmodus aus → Go-live-Check.
#   Schlägt ein Schritt vor dem Wartungsmodus fehl, läuft die Seite unverändert
#   weiter. Danach bleibt sie im Wartungsmodus (sicherer als halb
#   aktualisierter Code); das Skript nennt den vorherigen Commit für den
#   Rückweg (deploy/README.md „Rückroll“). Fehler beheben und erneut starten,
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
# kopiert -- sonst wird auf dem Server gebaut (vor dem Wartungsmodus). Enthält
# BUILD_DIR eine build-sha.txt, muss sie zum ausgerollten Commit passen.
#
# Inhalte: `db:seed` (Fragenkatalog usw.) läuft nur, wenn sich seit dem zuletzt
# ausgerollten Commit (storage/app/deployed_sha) etwas unter database/seeders
# oder database/data geändert hat, beim ersten Deploy ohne gespeicherten Stand
# oder mit --with-content.
#
# Sicherung: `php artisan app:backup --deploy` (nur lokal, die letzten
# BACKUP_KEEP_DEPLOY bleiben) vor jeder Migration.
set -Eeuo pipefail

STATE_REL="storage/app/deployed_sha"
CONTENT_PATHS=(database/seeders database/data)
KEEP_RELEASES="${KEEP_RELEASES:-5}"
GIT_ARGS=()
BRANCH=""
TARGET=""
WITH_CONTENT=0
ROLLBACK=0
BUILD_DIR="${BUILD_DIR:-}"
# Zustand für on_error.
PREV=""
DOWN=0
SWITCHED=0
REL=""

# Alles steht in main() (Aufruf ganz unten, in einer Zeile mit exit): Bash
# liest das Skript sonst stückweise, und `git merge` ersetzt diese Datei im
# Modus inplace mitten im Lauf.
main() {
    parse_args "$@"

    # Physischer Pfad (current ist im Modus releases ein Symlink auf den Release-Ordner).
    APP_DIR="$(cd -P "$(dirname "$0")/.." && pwd -P)"
    cd "$APP_DIR"

    if [ -z "${DEPLOY_MODE:-}" ]; then
        if [ "$(basename "$(dirname "$APP_DIR")")" = "releases" ]; then DEPLOY_MODE=releases; else DEPLOY_MODE=inplace; fi
    fi
    case "$DEPLOY_MODE" in
        inplace|releases) ;;
        *) die "Unbekannter DEPLOY_MODE: $DEPLOY_MODE (inplace oder releases)" ;;
    esac
    if [ "$ROLLBACK" = 1 ] && [ "$DEPLOY_MODE" != releases ]; then
        die "--rollback gibt es nur mit DEPLOY_MODE=releases (im Modus inplace: deploy/README.md „Rückroll“)."
    fi

    if [ "$DEPLOY_MODE" = releases ]; then
        if [ "$ROLLBACK" = 1 ]; then rollback_releases; else deploy_releases; fi
    else
        deploy_inplace
    fi
}

parse_args() {
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
}

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$1"; }
info() { printf '    %s\n' "$1"; }
warn() { printf '\n\033[1;33m%s\033[0m\n' "$1"; }
die() { printf '\n\033[1;31m%s\033[0m\n' "$1" >&2; exit 1; }

# Nur ein Deploy zur Zeit (z. B. Knopf in GitHub und Hand-Deploy gleichzeitig).
lock() {
    command -v flock >/dev/null 2>&1 || die "flock fehlt (Paket util-linux) -- ohne Sperre kein Deploy."
    mkdir -p "$(dirname "$1")"
    exec 9>"$1"
    flock -n 9 || die "Es läuft bereits ein Deploy (Sperre $1)."
}

# Fehler nach dem Start: sagen, in welchem Zustand die Seite ist und wie es zurückgeht.
on_error() {
    local rc="$1" line="$2"
    trap - ERR
    printf '\n\033[1;31mDeploy abgebrochen (Zeile %s).\033[0m\n' "$line" >&2
    if [ "$DOWN" = 1 ]; then
        printf '\033[1;31mDie Seite bleibt im Wartungsmodus.\033[0m\n' >&2
    elif [ "$SWITCHED" = 0 ]; then
        printf 'Die Seite läuft unverändert mit dem bisherigen Stand weiter.\n' >&2
    fi
    if [ "$DEPLOY_MODE" = releases ]; then
        if [ "$SWITCHED" = 0 ]; then
            printf 'current zeigt weiter auf den bisherigen Stand (%s).\n' "${PREV:-keiner}" >&2
            if [ -n "$REL" ]; then cd / && rm -rf "$REL"; fi
            if [ "$DOWN" = 1 ]; then
                printf 'Fehler beheben und erneut starten -- oder ohne Update zurück: php artisan up\n' >&2
            fi
        else
            printf 'current zeigt bereits auf den neuen Stand. Zurück: ./deploy/deploy.sh --rollback (vorher: %s)\n' "${PREV:-keiner}" >&2
        fi
    else
        printf 'Vorheriger Commit: %s\n' "${PREV:-unbekannt}" >&2
        if [ "$DOWN" = 1 ] || [ "$SWITCHED" = 1 ]; then
            printf 'Rückweg: deploy/README.md „Rückroll“ (git checkout --detach %s, Abhängigkeiten, ggf. pg_restore der letzten …-deploy-Sicherung) -- oder Fehler beheben und erneut starten.\n' "${PREV:-<commit>}" >&2
        fi
    fi
    exit "$rc"
}

# Ist BUILD_DIR ein passender Vite-Build für Commit $1?
prebuilt_ok() {
    local sha="$1"
    [ -n "$BUILD_DIR" ] || return 1
    if [ ! -f "$BUILD_DIR/manifest.json" ]; then
        info "BUILD_DIR=$BUILD_DIR enthält keinen Vite-Build (manifest.json fehlt) -- baue auf dem Server."
        return 1
    fi
    if [ -f "$BUILD_DIR/build-sha.txt" ] && [ "$(tr -d '[:space:]' < "$BUILD_DIR/build-sha.txt")" != "$sha" ]; then
        info "Build in $BUILD_DIR gehört zu einem anderen Commit -- baue auf dem Server."
        return 1
    fi
    return 0
}

# Vite-Build nach $1/public/build: aus BUILD_DIR übernehmen oder bauen ($2 = Commit).
# $1 ist ein Ordner, den die laufende Seite nicht benutzt (Vorbereitungsordner bzw. neuer Release).
prepare_assets() {
    local dir="$1" sha="$2"
    if prebuilt_ok "$sha"; then
        info "Vorgebaute Assets aus $BUILD_DIR übernehmen (kein Build auf dem Server)."
        rm -rf "$dir/public/build"
        mkdir -p "$dir/public/build"
        cp -R "$BUILD_DIR"/. "$dir/public/build/"
        rm -f "$dir/public/build/build-sha.txt"
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
    # PHP_FPM_SERVICE=php8.5-fpm setzen, wenn der Deploy-Benutzer reloaden darf.
    if [ -n "${PHP_FPM_SERVICE:-}" ]; then sudo systemctl reload "$PHP_FPM_SERVICE"; fi
}

remove_staging() {
    git worktree remove --force "$1" >/dev/null 2>&1 || true
    rm -rf "$1"
    git worktree prune
}

# =============================================================================
# Modus inplace (Standard): ein Checkout, der aktualisiert wird.
# =============================================================================
deploy_inplace() {
    local staging="$APP_DIR/.deploy/staging" new last_sha
    lock "$APP_DIR/.deploy/lock"

    PREV="$(git rev-parse HEAD)"
    trap 'on_error $? $LINENO' ERR

    step "Code holen ($BRANCH${TARGET:+ @ $TARGET}), noch ohne umzuschalten"
    git fetch --quiet origin "$BRANCH"
    new="$(git rev-parse --verify --quiet "${TARGET:-origin/$BRANCH}^{commit}")" \
        || { printf 'Commit %s nicht gefunden.\n' "${TARGET:-origin/$BRANCH}" >&2; false; }
    if ! git merge-base --is-ancestor "$new" "origin/$BRANCH"; then
        printf 'Commit %s liegt nicht auf %s -- abgebrochen.\n' "$new" "$BRANCH" >&2
        false
    fi
    # HEAD und der lokale Branch dürfen nur vorwärts gehen (keine lokalen Commits überschreiben).
    if ! git merge-base --is-ancestor HEAD "$new" \
        || { git show-ref --verify --quiet "refs/heads/$BRANCH" && ! git merge-base --is-ancestor "refs/heads/$BRANCH" "$new"; }; then
        printf 'Kein Fast-forward von %s auf %s -- abgebrochen.\n' "$PREV" "$new" >&2
        false
    fi
    printf 'Vorher: %s\nNeu:    %s\n' "$(git log -1 --format='%h %s' "$PREV")" "$(git log -1 --format='%h %s' "$new")"
    last_sha="$(cat "$STATE_REL" 2>/dev/null || true)"

    step "Abhängigkeiten und Assets vorbereiten (Seite läuft weiter)"
    remove_staging "$staging"
    git worktree add --quiet --detach "$staging" "$new"
    # --no-scripts: package:discover läuft nach dem Umschalten im App-Verzeichnis.
    composer install --working-dir="$staging" --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --no-scripts
    prepare_assets "$staging" "$new"

    step "Wartungsmodus an"
    php artisan down --retry=15 --refresh=15 || true
    DOWN=1

    step "Sicherung vor dem Update"
    # Migrationen sind nur vorwärts gedacht: vorher immer ein frischer Dump
    # (nur lokal, die letzten BACKUP_KEEP_DEPLOY bleiben).
    php artisan app:backup --deploy

    step "Umschalten auf $(git log -1 --format='%h' "$new")"
    SWITCHED=1
    # Fast-forward ist oben geprüft; -B setzt den Branch genau auf $new (auch
    # wenn er lokal noch fehlt oder HEAD nach einem Rückroll losgelöst ist).
    git checkout --quiet -B "$BRANCH" "$new"
    # Vorbereitete Abhängigkeiten/Assets per Umbenennen übernehmen (Composer-
    # Autoloader und Vite-Build sind nicht an den Ordner gebunden).
    rm -rf vendor.old public/build.old
    if [ -d vendor ]; then mv vendor vendor.old; fi
    mv "$staging/vendor" vendor
    mkdir -p public
    if [ -d public/build ]; then mv public/build public/build.old; fi
    mv "$staging/public/build" public/build
    rm -rf vendor.old public/build.old
    php artisan package:discover --ansi

    step "Migrationen (Owner-Rolle)"
    php artisan migrate --database=pgsql_admin --force

    # Katalog-Updates (Fragen, Erklärungen, Navigation, Praxistrainer) kommen als
    # Seeder mit; die Seeder sind idempotent und lassen Superadmin-Korrekturen
    # stehen. Sie laufen aber mehrere Minuten -- deshalb nur bei Bedarf.
    step "Inhalte (Seeder)"
    if seed_needed "$last_sha" "$new"; then
        php artisan db:seed --database=pgsql_admin --force
    fi
    # Erst nach erfolgreichen Migrationen/Seedern merken -- ein vorher
    # abgebrochener Deploy spielt die Inhalte beim nächsten Lauf erneut ein.
    mkdir -p "$(dirname "$STATE_REL")"
    printf '%s\n' "$new" > "$STATE_REL"

    step "Storage-Link"
    [ -e public/storage ] || php artisan storage:link

    step "Caches"
    php artisan optimize
    # Queue-Worker laden den neuen Code.
    php artisan queue:restart
    # Kursliste der App vorwärmen (sonst ist der erste Aufruf nach dem Deploy langsam).
    php artisan app:warm-course-cache || true
    reload_fpm

    step "Wartungsmodus aus"
    php artisan up
    DOWN=0

    remove_staging "$staging"

    step "Go-live-Check"
    php artisan app:check-production || warn 'Hinweis: der Go-live-Check meldet offene Punkte (siehe oben).'

    printf '\n\033[1;32mDeploy fertig: %s (vorher %s)\033[0m\n' "$(git log -1 --format='%h %s')" "$(git log -1 --format='%h' "$PREV")"
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
    local base repo shared rel new_sha last_sha previous url
    base="$(release_base)"
    repo="$base/repo.git"
    shared="$base/shared"

    mkdir -p "$base/releases"
    [ -f "$shared/.env" ] || die "$shared/.env fehlt (beim Umstieg aus dem bisherigen App-Ordner kopieren, siehe deploy/README.md)."
    [ -d "$shared/storage" ] || die "$shared/storage fehlt (beim Umstieg aus dem bisherigen App-Ordner übernehmen, siehe deploy/README.md)."
    mkdir -p "$shared/storage/app/public" "$shared/storage/framework/cache" "$shared/storage/framework/sessions" \
        "$shared/storage/framework/views" "$shared/storage/logs"

    # Nie zwei Deploys gleichzeitig in denselben Basisordner.
    lock "$base/.deploy.lock"

    previous=""
    if [ -L "$base/current" ]; then previous="$(readlink -f "$base/current")"; fi
    PREV="${previous:+$(basename "$previous")}"
    trap 'on_error $? $LINENO' ERR

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
    # Wie im Modus inplace: nur Stände, die auf dem Branch liegen.
    git "${GIT_ARGS[@]}" merge-base --is-ancestor "$new_sha" "refs/heads/$BRANCH" \
        || die "Commit $new_sha liegt nicht auf $BRANCH."

    rel="$base/releases/$(date +%Y%m%d%H%M%S)-${new_sha:0:12}"
    [ ! -e "$rel" ] || die "$rel existiert bereits."
    # Ab hier entfernt on_error den halben Ordner, solange current nicht umgeschaltet ist.
    REL="$rel"
    mkdir -p "$rel"
    git "${GIT_ARGS[@]}" archive "$new_sha" | tar -x -C "$rel"
    printf '%s\n' "$new_sha" > "$rel/REVISION"
    info "Neuer Stand: $rel"

    # Gemeinsame Daten: storage/ und .env gelten für alle Stände.
    rm -rf "$rel/storage"
    ln -s "$shared/storage" "$rel/storage"
    ln -s "$shared/.env" "$rel/.env"
    mkdir -p "$rel/bootstrap/cache"
    cd "$rel"

    step "PHP-Abhängigkeiten (der bisherige Stand läuft weiter)"
    # vendor/ des laufenden Stands als Ausgangspunkt (spart Downloads).
    if [ -n "$previous" ] && [ -d "$previous/vendor" ]; then cp -a "$previous/vendor" "$rel/vendor"; fi
    composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress

    step "Assets"
    prepare_assets "$rel" "$new_sha"

    step "Storage-Link"
    php artisan storage:link

    step "Caches (neuer Stand, der bisherige läuft weiter)"
    php artisan optimize

    last_sha="$(cat "$shared/$STATE_REL" 2>/dev/null || true)"

    step "Wartungsmodus an"
    php artisan down --retry=15 --refresh=15 || true
    DOWN=1

    step "Sicherung vor dem Update"
    # Migrationen sind nur vorwärts gedacht: vorher immer ein frischer Dump
    # (nur lokal, die letzten BACKUP_KEEP_DEPLOY bleiben).
    php artisan app:backup --deploy

    step "Migrationen (Owner-Rolle, aus dem neuen Stand)"
    php artisan migrate --database=pgsql_admin --force

    step "Inhalte (Seeder)"
    if seed_needed "$last_sha" "$new_sha"; then
        php artisan db:seed --database=pgsql_admin --force
    fi

    step "Umschalten: current -> $(basename "$rel")"
    switch_current "$base" "$rel"
    SWITCHED=1
    mkdir -p "$(dirname "$shared/$STATE_REL")"
    printf '%s\n' "$new_sha" > "$shared/$STATE_REL"
    # Queue-Worker beenden sich und starten über <basis>/current/artisan mit
    # dem neuen Code (supervisor).
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

    printf '\n\033[1;32mDeploy fertig: %s (vorher %s)\n%s\033[0m\n' \
        "$(git "${GIT_ARGS[@]}" log -1 --format='%h %s' "$new_sha")" "${PREV:-keiner}" "current -> $rel"
}

rollback_releases() {
    local base active previous sha
    base="$(release_base)"
    lock "$base/.deploy.lock"
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

main "$@"; exit $?
