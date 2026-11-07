#!/usr/bin/env bash
# Update auf dem Server (im App-Verzeichnis ausführen, als Deploy-Benutzer,
# nicht als root):  ./deploy/deploy.sh [branch]
#
# Ablauf: Wartungsmodus → Code holen → Abhängigkeiten → Assets bauen →
# Migrationen (Owner-Rolle) → Inhalte (Seeder) → Storage-Link → Caches → Wartungsmodus aus →
# Go-live-Check. Schlägt ein Schritt fehl, bleibt die Seite im
# Wartungsmodus (sicherer als halb aktualisierter Code) -- Fehler beheben
# und das Skript erneut starten, oder mit `php artisan up` zurück.
set -euo pipefail

BRANCH="${1:-main}"
cd "$(dirname "$0")/.."

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$1"; }
trap 'printf "\n\033[1;31mDeploy abgebrochen (Zeile %s). Die Seite bleibt im Wartungsmodus.\033[0m\n" "$LINENO"' ERR

step "Wartungsmodus an"
php artisan down --retry=15 --refresh=15 || true

step "Code holen ($BRANCH)"
git fetch --quiet origin "$BRANCH"
git checkout --quiet "$BRANCH"
git merge --ff-only --quiet "origin/$BRANCH"

step "PHP-Abhängigkeiten"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress

step "Assets bauen"
npm ci --no-audit --no-fund --silent
npm run build --silent

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
# Opcache leeren (bei opcache.validate_timestamps=0 sonst alter Code):
# PHP_FPM_SERVICE=php8.3-fpm setzen, wenn der Deploy-Benutzer reloaden darf.
if [ -n "${PHP_FPM_SERVICE:-}" ]; then sudo systemctl reload "$PHP_FPM_SERVICE"; fi

step "Wartungsmodus aus"
php artisan up

step "Go-live-Check"
php artisan app:check-production || printf '\n\033[1;33mHinweis: der Go-live-Check meldet offene Punkte (siehe oben).\033[0m\n'

printf '\n\033[1;32mDeploy fertig: %s\033[0m\n' "$(git log -1 --format='%h %s')"
