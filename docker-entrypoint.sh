#!/usr/bin/env sh
set -eu

cd /var/www/html
if [ -d /opt/lhiestore-public-build ]; then
    rm -rf public/build
    mkdir -p public
    cp -R /opt/lhiestore-public-build public/build
fi


if [ "${APP_AUTO_MIGRATE:-true}" = "true" ]; then
    php artisan migrate --force
fi

if [ "${APP_AUTO_SET_TELEGRAM_WEBHOOK:-false}" = "true" ]; then
    php artisan telegram:webhook:set || echo "WARNING: Telegram webhook setup failed; continuing startup."
fi

exec "$@"
