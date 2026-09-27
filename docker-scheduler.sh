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
    # The heartbeat proves the scheduler loop is alive. A full schedule:run
    # cycle can legitimately outlast the deploy health window, so stamp before
    # running the cycle instead of only after a successful one.
    started_at=$(date +%s)
    date -u +"%Y-%m-%dT%H:%M:%SZ" > "$HEARTBEAT_FILE"

    if ! run_scheduler; then
        echo "WARNING: schedule:run exited non-zero; the scheduler loop continues." >&2
    fi

    # Keep the loop aligned to the requested interval. A cycle that already
    # consumed most of the interval must not add a full sleep on top, or
    # every-minute commands drift to roughly half of their intended cadence.
    elapsed=$(( $(date +%s) - started_at ))
    remaining=$(( INTERVAL_SECONDS - elapsed ))
    if [ "$remaining" -lt 1 ]; then
        remaining=1
    fi

    sleep "$remaining"
done
