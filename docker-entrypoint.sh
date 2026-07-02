#!/usr/bin/env sh
set -eu

cd /var/www/html

mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views storage/app/public bootstrap/cache

if [ "$(id -u)" = "0" ]; then
    install -d -o laravel -g laravel -m 0775 storage/logs storage/framework/cache storage/framework/sessions storage/framework/views storage/app/public bootstrap/cache
    chown -R laravel:laravel storage/logs
    chmod -R ug+rwX storage/logs
    chmod 2775 storage/logs
fi

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
