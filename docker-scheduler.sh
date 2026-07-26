#!/usr/bin/env sh
set -eu

cd /var/www/html

HEARTBEAT_FILE=${SCHEDULER_HEARTBEAT_FILE:-storage/framework/scheduler-heartbeat}
INTERVAL_SECONDS=${SCHEDULER_INTERVAL_SECONDS:-60}

# Drop privileges to the unprivileged application user when the container starts
# as root, matching docker-entrypoint.sh, so scheduled commands never run as root.
run_scheduler() {
    if [ "$(id -u)" = "0" ]; then
        su -s /bin/sh laravel -c 'php artisan schedule:run'
    else
        php artisan schedule:run
    fi
}

while true; do
    if run_scheduler; then
        date -u +"%Y-%m-%dT%H:%M:%SZ" > "$HEARTBEAT_FILE"
    fi

    sleep "$INTERVAL_SECONDS"
done
