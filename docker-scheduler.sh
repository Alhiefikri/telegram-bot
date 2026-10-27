#!/usr/bin/env sh
set -eu

APP_PATH=${SCHEDULER_APP_PATH:-/var/www/html}
cd "$APP_PATH"

HEARTBEAT_FILE=${SCHEDULER_HEARTBEAT_FILE:-storage/framework/scheduler-heartbeat}
RUNNING_FILE=${SCHEDULER_RUNNING_FILE:-storage/framework/scheduler-running}
OOM_COUNT_FILE=${SCHEDULER_OOM_COUNT_FILE:-storage/framework/scheduler-oom-kill-count}
OOM_EVENTS_FILE=${SCHEDULER_OOM_EVENTS_FILE:-/sys/fs/cgroup/memory.events}
INTERVAL_SECONDS=${SCHEDULER_INTERVAL_SECONDS:-60}
mkdir -p "$(dirname "$HEARTBEAT_FILE")"

run_artisan() {
    if [ "$(id -u)" = "0" ]; then
        su -s /bin/sh laravel -c "cd \"$APP_PATH\" && php artisan $*"
    else
        php artisan "$@"
    fi
}

run_scheduler() {
    run_artisan ops:schedule-run
}

report_runtime_failure() {
    reason=$1
    if ! run_artisan ops:scheduler-runtime-alert scheduler failed "--reason=$reason"; then
        echo "WARNING: scheduler runtime failure could not be persisted." >&2
        return 1
    fi
}

record_oom_kill_count() {
    OOM_DETECTED=0
    current_count=
    if [ -r "$OOM_EVENTS_FILE" ]; then
        while IFS=' ' read -r event_name event_value; do
            if [ "$event_name" = "oom_kill" ]; then
                current_count=$event_value
                break
            fi
        done < "$OOM_EVENTS_FILE"
    fi

    case "$current_count" in
        ''|*[!0-9]*) return 0 ;;
    esac

    previous_count=
    if [ -r "$OOM_COUNT_FILE" ]; then
        IFS= read -r previous_count < "$OOM_COUNT_FILE" || true
    fi
    case "$previous_count" in
        ''|*[!0-9]*) previous_count=$current_count ;;
    esac

    if [ "$current_count" -gt "$previous_count" ]; then
        OOM_DETECTED=1
        if ! report_runtime_failure oom_kill; then
            return 1
        fi
    fi

    printf '%s\n' "$current_count" > "$OOM_COUNT_FILE.tmp"
    mv "$OOM_COUNT_FILE.tmp" "$OOM_COUNT_FILE"
}

if [ -f "$RUNNING_FILE" ]; then
    if report_runtime_failure cycle_interrupted; then
        rm -f "$RUNNING_FILE"
    fi
fi

record_oom_kill_count || true

while true; do
    started_at=$(date +%s)
    date -u +"%Y-%m-%dT%H:%M:%SZ" > "$RUNNING_FILE.tmp"
    mv "$RUNNING_FILE.tmp" "$RUNNING_FILE"

    cycle_status=0
    if run_scheduler; then
        :
    else
        cycle_status=$?
        echo "WARNING: ops:schedule-run exited non-zero (status=$cycle_status); the scheduler loop continues." >&2
        if report_runtime_failure schedule_exit; then
            rm -f "$RUNNING_FILE"
        fi
    fi

    if record_oom_kill_count; then
        if [ "$cycle_status" -eq 0 ] && [ "$OOM_DETECTED" -eq 0 ]; then
            if ! run_artisan ops:scheduler-runtime-alert scheduler recovered; then
                echo "WARNING: scheduler recovery could not be persisted." >&2
            fi
            date -u +"%Y-%m-%dT%H:%M:%SZ" > "$HEARTBEAT_FILE.tmp"
            mv "$HEARTBEAT_FILE.tmp" "$HEARTBEAT_FILE"
            rm -f "$RUNNING_FILE"
        elif [ "$OOM_DETECTED" -eq 1 ]; then
            rm -f "$RUNNING_FILE"
        fi
    fi

    elapsed=$(( $(date +%s) - started_at ))
    printf 'scheduler cycle duration_seconds=%s\n' "$elapsed"
    remaining=$(( INTERVAL_SECONDS - elapsed ))
    if [ "$remaining" -lt 1 ]; then
        remaining=1
    fi

    sleep "$remaining"
done
