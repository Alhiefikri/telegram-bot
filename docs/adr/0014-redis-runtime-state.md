# Redis for queues and transient runtime state

## Status

Accepted — issue #519

## Context

The previous Docker Compose runtime used Laravel's file cache and file sessions with a database-backed queue. Supervisor ran six polling workers against that queue. The repository configuration establishes the polling topology; the production CPU percentage reported by issue #519 has not been independently measured in this change.

## Decision

- Add a private `redis:7-alpine` Compose service with no published port or persistent Docker volume. The upstream image declares `/data` as a volume, so Compose overrides it with a 1 MiB tmpfs. RDB snapshots and AOF are disabled.
- Use Redis for the Compose runtime's Laravel queue, cache, sessions, and locks. PostgreSQL remains the durable domain database and stores failed jobs.
- Cap Redis at 96 MiB `maxmemory` and the container at 128 MiB, with a 0.25 CPU limit.
- Use `maxmemory-policy noeviction`, not the issue's suggested `allkeys-lru`. Queue payloads share Redis with cache and session keys; an LRU policy could silently evict queued work. At the memory cap, Redis rejects writes visibly instead of evicting queue data. This makes write failures possible under pressure; it does not make the queue durable.
- Set Redis queue `retry_after` to 180 seconds, above the longest configured worker timeout of 120 seconds.
- Before Redis-backed application startup, fail closed if the PostgreSQL `jobs` table contains any rows. Before automatic rollback from Redis to the database queue, stop candidate producers and require all operational Redis queues—including delayed and reserved jobs—to be empty and inspectable.

## Memory budget and operational trade-offs

The Redis service has 96 MiB for Redis-managed data and a 128 MiB container limit, leaving a 32 MiB nominal container headroom for process/allocator overhead. The existing Compose container ceilings are 896 MiB for the app, 896 MiB for PostgreSQL, 256 MiB for the scheduler, and 160 MiB for Nginx. Including Redis, their aggregate ceilings are 2,336 MiB. These are independent ceilings, not reserved memory or observed RSS; their sum exceeds the reported 1.9 GB host capacity. Actual production container and host memory/CPU measurements have not been captured here, so this change does not claim a verified capacity margin or CPU reduction. Compare `docker stats --no-stream` and host memory under comparable load before and after rollout.

Redis removes queue polling and queue writes from PostgreSQL; cache/session state moves from the previous file-backed drivers into Redis. This trades database queue work for a separate 128 MiB container ceiling and 96 MiB Redis data cap. Because Redis has neither persistence nor a persistent volume, a Redis process/container/host restart can discard queued jobs, sessions, cache entries, and locks. `noeviction` prevents silent eviction at the cap but does not protect against restart or host failure. Treat Redis availability and restart as a correctness risk; do not describe this topology as durable or claim zero loss across an unexpected Redis failure.

The initial driver change does not migrate file-backed sessions or cache entries. Active sessions will be lost at cutover and users may need to authenticate again; cache state is disposable.

The startup and rollback guards protect the planned database-to-Redis cutover and a driver rollback. They cannot recover data already lost by a Redis failure. Follow [the runtime cutover runbook](../runbook-redis-runtime-cutover.md) and verify the live queue and failed-job state during deployment.