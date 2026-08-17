#!/usr/bin/env bash
#
# Despliega el commit actual (HEAD) de este repo local al VPS
# (cod2.4livepro.com), via `git archive | ssh tar`. Mismo patron que
# desarrollo.4livepro.com.
#
# Uso:
#   ./deploy.sh                # deploy normal
#   ./deploy.sh --migrate      # ademas corre `php artisan migrate --force`
#   ./deploy.sh --composer     # corre `composer install` (usar si composer.json/lock cambio)
#
set -euo pipefail

SSH_HOST="iptvwatch"
REMOTE_PATH="/var/www/cod2.4livepro.com"
DOMAIN="https://cod2.4livepro.com"

RUN_MIGRATE=0
RUN_COMPOSER=0

for arg in "$@"; do
    case "$arg" in
        --migrate) RUN_MIGRATE=1 ;;
        --composer) RUN_COMPOSER=1 ;;
        -h|--help) sed -n '2,12p' "$0"; exit 0 ;;
        *) echo "Argumento desconocido: $arg (usa --help)" >&2; exit 1 ;;
    esac
done

step() { echo -e "\n==> $*"; }

if [ -n "$(git status --porcelain)" ]; then
    echo "Hay cambios sin commitear. Haz commit antes de desplegar (o el deploy no los incluira)." >&2
    git status --short
    exit 1
fi

COMMIT_SHA=$(git rev-parse --short HEAD)
COMMIT_MSG=$(git log -1 --pretty=%s)

step "Desplegando commit ${COMMIT_SHA} (\"${COMMIT_MSG}\") a ${REMOTE_PATH}"
git archive HEAD | ssh "$SSH_HOST" "tar -x -C '$REMOTE_PATH'"

step "Permisos"
ssh "$SSH_HOST" "chown -R www-data:www-data '$REMOTE_PATH' && chmod +x '$REMOTE_PATH/artisan'"

if [ "$RUN_COMPOSER" -eq 1 ]; then
    step "Dependencias PHP (composer install)"
    ssh "$SSH_HOST" "cd '$REMOTE_PATH' && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader"
fi

if [ "$RUN_MIGRATE" -eq 1 ]; then
    step "Migraciones"
    ssh "$SSH_HOST" "cd '$REMOTE_PATH' && php artisan migrate --force"
fi

step "Cache de config/routes/views"
ssh "$SSH_HOST" "cd '$REMOTE_PATH' && php artisan optimize"

# optimize runs as root (over ssh) and recreates storage/framework/views/* as root,
# which then blocks www-data (Apache/PHP) from touching those files at request time —
# so ownership has to be fixed again after, not just before, optimize runs.
step "Permisos (post-optimize)"
ssh "$SSH_HOST" "chown -R www-data:www-data '$REMOTE_PATH/storage' '$REMOTE_PATH/bootstrap/cache'"

step "Listo: ${DOMAIN}"
