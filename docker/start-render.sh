#!/bin/sh
set -eu

port="${PORT:-10000}"
export APP_URL="${RENDER_EXTERNAL_URL:-${APP_URL:-http://127.0.0.1:${port}}}"

sed -ri "s/^Listen [0-9]+/Listen ${port}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${port}>/" /etc/apache2/sites-available/000-default.conf

php artisan db:prepare-production --no-interaction
php artisan migrate --force --no-interaction
php artisan view:clear --no-interaction
php artisan cache:clear --no-interaction
php artisan route:clear --no-interaction
php artisan config:clear --no-interaction

if [ "${TRILHAGOV_SEED_DEMO:-false}" = "true" ]; then
    php artisan trilhagov:demo-guapiara --force --no-interaction \
        || echo "Aviso: seed de demonstracao falhou, prosseguindo sem interromper o boot." >&2
fi

php artisan optimize

exec apache2-foreground
