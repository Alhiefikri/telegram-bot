#!/bin/sh
set -eu
cd /var/www/html
# Never execute the production entrypoint, cached configuration, queue or scheduler.
rm -f bootstrap/cache/*.php
rm -f .env .env.*
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
exec php artisan serve --host=0.0.0.0 --port=8080 --no-reload
