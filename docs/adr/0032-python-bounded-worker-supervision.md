# Python candidate bounded worker supervision

## Status

Accepted for the Django migration experiment (#691, part of #679). It does not
activate production, change Compose, or replace ADR-0030 for the running
Laravel application; production activation remains behind H1/H2/H3.

## Context

The P09 receipt slice already uses the existing `telegram_outbound_effects`
ledger as the delivery authority and ID-only Redis lists as disposable
wakeups. P06 navigation stores each accepted update in PostgreSQL and P08
payment settlement is driven by authenticated hints plus authoritative provider
observations. None of these paths had a long-lived, bounded consumer.

ADR-0030 isolates PHP queues because a running job is not preempted by queue
priority. The same failure mode applies to a Python process: a slow provider
call or a stuck receipt render must not delay navigation or another receipt.
Redis in the repository has no persistence; PostgreSQL is already the durable
owner of receipt effects, payment events, navigation updates and money state.

## Decision

- Use no third-party queue/worker library for this slice. Celery, RQ, Huey or a
  broker add a new service/dependency and a second scheduling vocabulary before
  a measured need exists. The bounded supervisor uses Python's standard library
  and the existing PostgreSQL/Redis primitives.
- Start one process per family: `interaction`, `payment`, `receipt`,
  `notifications`, `bulk`, `mail` and `auth-mail`. P25 (#707) extends the three
  original P10 consumers; no family shares an OS process with another. Including
  the connection-free supervisor and HTTP process, the runtime has nine processes
  and at most eight `candidate_runtime` connections. The role limit is ten,
  reserving two connections for disposable diagnostics. The whole runtime remains
  inside P01's application allocation (1.25 CPU / 1216 MiB), not an additional
  allocation beyond the complete stack's 2 CPU / 2 GiB ceiling.
- PostgreSQL remains the durable work source. Redis receives only a fixed
  Python JSON envelope with `version`, `kind` and a stable integer/UUID
  identity. PHP serialization, ORM snapshots, amounts, credentials and customer
  payloads are rejected before a handler runs. A lost wakeup only loses a hint;
  every worker performs a bounded durable sweep on an interval and after Redis
  errors.
- Preserve the existing claim/lease/fencing/ordering protocols. Receipt claims
  keep their token, lease, attempt ceiling and unknown-delivery rule. Payment
  observation stays outside money transactions and revalidates under lock.
  Navigation keeps its per-chat advisory lock, pending-order check and
  no-blind-replay rule. No second ledger or outbox is created.
- The payment worker also processes admitted checkout and payment-check
  navigation callbacks from the existing navigation inbox. It claims and
  validates the update under the per-chat advisory lock, releases the lock for
  provider I/O, then reacquires it and revalidates the claim, Customer, root
  generation and navigation session before rendering. Earlier processing
  updates fence later same-chat work. Fair bounded sweeps skip blocked chats.
- Payment sweeps interleave navigation, provider hints and recovery. Each
  operation gets its own timeout; a 60-second scheduling budget stops new work
  without interrupting an operation already in flight.
- Keep the timeout/retry envelope compatible with the Laravel topology: job
  timeout 60 s, `retry_after`-aligned receipt lease 180 s, three receipt
  attempts, and a 75 s graceful-shutdown window. A SIGTERM stops pickup,
  finishes the current bounded job and closes connections. A supervisor that
  exceeds its bounded restart window stops the stack instead of crash-looping.
- Record bounded, secret-free counters/durations for pickup, finish, failure,
  timeout, Redis unavailability and sweeps. Durable timestamps remain the
  authority: navigation `accepted_at/claimed_at/completed_at`, payment
  `received_at/paid_at`, and receipt `created_at/first_pickup_at/sent_at`.

## Consequences

- A slow payment provider or a slow non-receipt family cannot delay navigation
  or receipts because they have separate processes and disjoint families.
- Redis loss no longer stops required work; the next sweep delivers due receipt
  effects directly from PostgreSQL. Unknown deliveries are never resent.
- P10's original five-process evidence remains historical evidence for that
  composition. P25 measures the extended nine-process composition separately;
  see its source-bound manifest and [notification evidence](../migration/python-django/p25/README.md).
  Short synthetic measurements prove the exercised isolation boundary, not
  production capacity or a latency distribution.
- A crashed child is restarted a bounded number of times inside a 60 s window.
  If the failure repeats, the supervisor stops the runtime rather than hiding a
  persistent fault.
- Notification/admin/proof effects use `notifications`; broadcast/stock/price use
  `bulk`; credential, guest and waitlist mail use `mail`. Authentication mail
  retains P19's authority in `auth-mail`. Empty recipient admission is a no-op.
  These consumers are installed only by explicitly admitted worker composition;
  importing a module or starting HTTP does not activate them. Supplier acquisition
  remains payment-owned under its existing bounded contract, never moved onto
  interaction or receipt by this extension.
