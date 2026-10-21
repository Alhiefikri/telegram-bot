# Redis runtime cutover and rollback

This runbook covers the first move from the database queue to the Compose Redis queue and a later rollback to the database driver. Production releases and rollbacks must use the repository's GitHub Actions release workflow; this document does not authorize a direct production deployment.

## Before the first Redis cutover

1. Schedule a release window and confirm the existing release is healthy. Review current failed jobs; the release script records a failed-job watermark. Capture host/container CPU and memory under representative load. The repository does not contain a verified production before/after measurement for issue #519.
2. On the still-database-backed release, request graceful worker restarts and count all rows in PostgreSQL's `jobs` table. This PHP check works on the pre-cutover image and includes ready, reserved, and delayed jobs:

   ```bash
   docker compose exec -T app php artisan queue:restart
   docker compose exec -T app php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $depth = (int) $app["db"]->table("jobs")->count(); echo "database_jobs_depth={$depth}\n"; exit($depth === 0 ? 0 : 1);'
   ```

   Continue only when the check exits successfully with `database_jobs_depth=0`. Do not delete rows from `jobs` to make it pass. Repeat while workers finish any reserved jobs.

3. If work is still queued, let the existing workers finish and repeat the check. A job dispatched after the check is still protected: candidate startup refuses to proceed while PostgreSQL's `jobs` table contains any rows, and the release workflow restores the prior database-backed image if candidate health fails.

4. Deploy through the GitHub Actions production release workflow. Do not remove or manually recreate the Redis service during cutover.

## After cutover

The first driver change does not import file-backed sessions or cache entries. Existing sessions end at cutover, so users may need to authenticate again; cache state warms on demand.

Run the operational checks against the Redis-backed release:

```bash
docker compose exec -T app php artisan ops:health --db --queue-schema --queue-depth --supervisor
docker compose exec -T app php artisan ops:check-queue-depth --include-reserved-and-delayed --max=0
docker compose exec -T app php artisan ops:check-failed-jobs --max=0
docker compose exec -T redis redis-cli INFO memory
docker stats --no-stream
```

A nonzero queue depth is not a cutover failure by itself if workers are actively processing; investigate sustained or growing backlog, failed jobs, Redis memory near 96 MiB, and container restarts. Compare CPU and memory observations with the recorded baseline under comparable workload. Do not infer a production CPU reduction from repository configuration or a synthetic benchmark.

KlikQRIS deferred callback verification uses the configured runtime queue connection on `payments`, never an explicit database connection. The callback event is persisted before deferral; the scheduled KlikQRIS reconciliation remains the recovery path if the volatile queue loses the wakeup. A provider outage must not leave a new row in the inactive PostgreSQL `jobs` table and block the next application startup.

## Rollback from Redis to the database queue

Use the GitHub Actions rollback operation. When the recorded previous release uses the database driver, the release script stops candidate `app` and `scheduler` producers, then runs the total-depth check against every operational Redis queue. Reserved and delayed jobs are included.

- If Redis cannot be inspected or any job remains, the rollback is blocked. The candidate app and scheduler are restarted with Redis still available; the release script does not switch queue drivers or remove Redis.
- Restore a healthy Redis-backed candidate and let it drain the queue, then retry rollback only after `ops:check-queue-depth --include-reserved-and-delayed --max=0` succeeds.
- Do not bypass this guard, change `QUEUE_CONNECTION` manually, or remove/restart Redis while it contains work. If the candidate cannot process the backlog, stop and use the normal incident-recovery process; a Redis restart can destroy its in-memory jobs.
- Once the queue is empty and inspectable, the release script may start the previous database-backed image and remove the Redis service as part of the old Compose topology.

## Durability limit

Redis is intentionally configured without a persistent volume, RDB snapshots, or AOF. The cutover guard and rollback check protect planned driver transitions, not Redis process/container/host failure. An unexpected Redis restart can lose queued jobs and transient session/cache/lock state; this topology cannot guarantee zero loss across that failure mode. The 96 MiB Redis data cap and `noeviction` policy prevent LRU eviction of queue payloads, but writes fail at the cap instead. See [ADR 0014](adr/0014-redis-runtime-state.md).