#!/usr/bin/env sh
set -eu

cd /var/www/html

HEARTBEAT_FILE=${SCHEDULER_HEARTBEAT_FILE:-storage/framework/scheduler-heartbeat}
INTERVAL_SECONDS=${SCHEDULER_INTERVAL_SECONDS:-60}

while true; do
    if php artisan schedule:run; then
        date -u +"%Y-%m-%dT%H:%M:%SZ" > "$HEARTBEAT_FILE"
    fi

    sleep "$INTERVAL_SECONDS"
done
