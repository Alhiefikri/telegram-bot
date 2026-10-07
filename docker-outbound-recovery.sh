#!/usr/bin/env sh
set -eu

cd /var/www/html

if [ "$(id -u)" = "0" ]; then
    exec su -s /bin/sh laravel -c 'cd /var/www/html && exec php artisan telegram:recover-outbound-worker'
fi

exec php artisan telegram:recover-outbound-worker
