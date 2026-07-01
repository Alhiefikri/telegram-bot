#!/usr/bin/env sh
set -eu

cd /var/www/html

if [ "${APP_AUTO_MIGRATE:-true}" = "true" ]; then
    php artisan migrate --force
fi

exec "$@"
