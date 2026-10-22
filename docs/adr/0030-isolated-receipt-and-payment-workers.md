# Isolated receipt and payment-verification workers

## Status

Accepted — issue #651 (part of #648)

## Context

The paid receipt handoff, its ordered successors, the text fallback and their
cleanup shared the `notifications` queue with credential email, admin notices,
payment proofs, Sales Proof posts and Store Balance balance notices. The
`payments` queue shared one worker with the default Telegram interaction queue.
Laravel queue priorities select the next job, but they do not preempt a job that
is already running. A slow notification therefore delayed the next receipt, and
a payment-verification backlog delayed bot updates.

The queues are Redis lists without persistence. Redis loss is expected to lose
wakeups; PostgreSQL remains the durable owner of receipt effects, admin notices
and recovery work.

## Decision

- `telegram-receipts` is the dedicated queue for `receipt.credentials`,
  `receipt.order_actions` and `receipt.txt_download`. The receipt text fallback
  and explicit resend remain the same family and use the same queue.
- `notifications` keeps email, admin notices, payment proofs, Sales Proof,
  Store Balance notices and post-receipt cleanup.
- `default` serves Telegram interactions only. `payments` has its own worker
  for deferred payment verification and cancellation reconciliation.
- Every receipt effect is routed by effect type, not only by its persisted
  `queue` value. Recovery, successor dispatch, fallback and explicit resend
  therefore cannot put a receipt back on the shared queue. Persisted metadata
  is normalized separately during cutover for operator visibility.
- The dispatcher still maps effect types to jobs and delegates claim, lease,
  fencing, ordering and ambiguity to `TelegramOutboundEffectLedgerProtocol`.
  No second state machine, outbox or queue payload containing credentials is
  introduced.
- The cutover command `telegram:receipt-queue-cutover` moves pending and
  delayed receipt jobs from the source queue to the receipt queue and rewrites
  persisted receipt-effect queue metadata. Reserved jobs are not stolen from
  their owner. The documented drain sequence stops the source worker, runs the
  cutover, then starts the receipt worker. A lost wakeup remains recoverable
  from PostgreSQL through `telegram:recover-outbound`.
- Worker concurrency starts at one process per queue. No queue gains a second
  `numprocs` without a measured memory/connection budget.

## Consequences

- Receipt wait time no longer includes unrelated notification work, and bot
  update pickup no longer includes payment-verification work.
- The app container runs two more PHP worker processes. The measured local
  worker RSS and the resulting 896 MiB container budget are recorded in
  [the queue topology note](../telegram-queue-topology.md); this is not a
  production capacity guarantee.
- The source `notifications` worker must still run for email, admin, proof,
  Sales Proof, cleanup and the non-receipt Store Balance families.
- Running the cutover with the source worker active is safe for pending jobs
  (the move is atomic per payload) but reserved jobs stay with their owner; the
  runbook requires a graceful stop and a bounded drain.
- No production deployment, real payment or customer message is implied by
  this decision.
