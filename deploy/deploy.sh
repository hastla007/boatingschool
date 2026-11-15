#!/usr/bin/env bash
# Update auf dem Server (im App-Verzeichnis ausführen, als Deploy-Benutzer,
# nicht als root):  ./deploy/deploy.sh [branch] [commit]
# Mit [commit] wird genau dieser (grün getestete) Stand ausgerollt.
#
# Ablauf: Sperre (nur ein Deploy zur Zeit) → Code holen, ohne umzuschalten →
# Abhängigkeiten und Assets in einem Vorbereitungsordner bauen (die Seite
# läuft dabei weiter) → Wartungsmodus → Sicherung → umschalten (Code,
# vendor/, public/build) → Migrationen (Owner-Rolle) → Inhalte (Seeder) →
# Storage-Link → Caches → Wartungsmodus aus → Go-live-Check.
#
# Schlägt ein Schritt vor dem Wartungsmodus fehl, läuft die Seite unverändert
# weiter. Danach bleibt sie im Wartungsmodus (sicherer als halb
# aktualisierter Code); das Skript nennt den vorherigen Commit für den
# Rückweg (deploy/README.md „Rückroll“). Fehler beheben und erneut starten,
# oder mit `php artisan up` zurück.
set -Eeuo pipefail

# Alles steht in main(): Bash liest das Skript sonst stückweise, und
# `git merge` ersetzt diese Datei mitten im Lauf.
main() {
    local branch="${1:-main}" target="${2:-}"
    cd "$(dirname "$0")/.."
    local staging="$PWD/.deploy/staging"

    # Nur ein Deploy zur Zeit (z. B. Knopf in GitHub und Hand-Deploy gleichzeitig).
    mkdir -p .deploy
    exec 9>.deploy/lock
    if ! flock -n 9; then
        printf '\033[1;31mEs läuft bereits ein Deploy (Sperre .deploy/lock).\033[0m\n'
        exit 1
    fi

    PREV="$(git rev-parse HEAD)"
    DOWN=0
    trap 'on_error $LINENO' ERR

    step "Code holen ($branch${target:+ @ $target}), noch ohne umzuschalten"
    git fetch --quiet origin "$branch"
    local new
    new="$(git rev-parse --verify "${target:-origin/$branch}^{commit}")"
    if ! git merge-base --is-ancestor HEAD "$new"; then
        printf 'Kein Fast-forward von %s auf %s -- abgebrochen.\n' "$PREV" "$new"
        false
    fi
    printf 'Vorher: %s\nNeu:    %s\n' "$(git log -1 --format='%h %s' "$PREV")" "$(git log -1 --format='%h %s' "$new")"

    step "Abhängigkeiten und Assets vorbereiten (Seite läuft weiter)"
    remove_staging "$staging"
    git worktree add --quiet --detach "$staging" "$new"
    # --no-scripts: package:discover läuft nach dem Umschalten im App-Verzeichnis.
    composer install --working-dir="$staging" --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --no-scripts
    npm ci --prefix "$staging" --no-audit --no-fund --silent
    npm run build --prefix "$staging" --silent

    step "Wartungsmodus an"
    php artisan down --retry=15 --refresh=15 || true
    DOWN=1

    step "Sicherung vor dem Update"
    # Migrationen sind nur vorwärts gedacht: vorher immer ein frischer Dump
    # (nur lokal, die letzten BACKUP_KEEP_DEPLOY bleiben).
    php artisan app:backup --deploy

    step "Umschalten auf $(git log -1 --format='%h' "$new")"
    git checkout --quiet "$branch"
    git merge --ff-only --quiet "$new"
    # Vorbereitete Abhängigkeiten/Assets per Umbenennen übernehmen (Composer-
    # Autoloader und Vite-Build sind nicht an den Ordner gebunden).
    rm -rf vendor.old public/build.old
    if [ -d vendor ]; then mv vendor vendor.old; fi
    mv "$staging/vendor" vendor
    if [ -d public/build ]; then mv public/build public/build.old; fi
    mv "$staging/public/build" public/build
    rm -rf vendor.old public/build.old
    php artisan package:discover --ansi

    step "Migrationen (Owner-Rolle)"
    php artisan migrate --database=pgsql_admin --force

    # Katalog-Updates (Fragen, Erklärungen, Navigation, Praxistrainer) kommen als
    # Seeder mit; die Seeder sind idempotent und lassen Superadmin-Korrekturen stehen.
    step "Inhalte aktualisieren (Seeder)"
    php artisan db:seed --database=pgsql_admin --force

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
    DOWN=0

    remove_staging "$staging"

    step "Go-live-Check"
    php artisan app:check-production || printf '\n\033[1;33mHinweis: der Go-live-Check meldet offene Punkte (siehe oben).\033[0m\n'

    printf '\n\033[1;32mDeploy fertig: %s (vorher %s)\033[0m\n' "$(git log -1 --format='%h %s')" "$(git log -1 --format='%h' "$PREV")"
}

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$1"; }

remove_staging() {
    git worktree remove --force "$1" >/dev/null 2>&1 || true
    rm -rf "$1"
    git worktree prune
}

on_error() {
    trap - ERR
    printf '\n\033[1;31mDeploy abgebrochen (Zeile %s).\033[0m\n' "$1"
    if [ "${DOWN:-0}" = 1 ]; then
        printf '\033[1;31mDie Seite bleibt im Wartungsmodus.\033[0m\n'
    else
        printf 'Die Seite läuft unverändert mit dem bisherigen Stand weiter.\n'
    fi
    printf 'Vorheriger Commit: %s\n' "${PREV:-unbekannt}"
    printf 'Rückweg: deploy/README.md „Rückroll“ (git checkout --detach %s, Abhängigkeiten, ggf. pg_restore der letzten …-deploy-Sicherung).\n' "${PREV:-<commit>}"
}

main "$@"
