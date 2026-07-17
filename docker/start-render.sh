#!/bin/sh
set -eu

port="${PORT:-10000}"
export APP_URL="${RENDER_EXTERNAL_URL:-${APP_URL:-http://127.0.0.1:${port}}}"

sed -ri "s/^Listen [0-9]+/Listen ${port}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${port}>/" /etc/apache2/sites-available/000-default.conf

php artisan db:prepare-production --no-interaction
php artisan migrate --force --no-interaction
php artisan optimize

exec apache2-foreground
