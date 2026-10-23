# Credential pin work outside the receipt critical path

## Status

Accepted — issue #652 (part of #648)

## Context

`ReceiptDeliveryPipeline` pinned the first credential-bearing buyer receipt
message synchronously after the receipt effect became `sent`, before it
dispatched the next ordered receipt chunk and before its `finally` block woke
the paid-order admin notification. `TelegramCredentialPinService` called
`pinChatMessage` through a transport with a 10 s response timeout and a 3 s
connect timeout. A slow or failing pin therefore held the receipt worker that
would otherwise deliver the next credential chunk.

Pinning is a convenience. The Order button and the authorized Order detail
remain the access path to credentials, and a pin never proves delivery. The
existing `telegram:recover-outbound` sweep already reconciled sent effects that
had no pin, so a lost pin wakeup already had a durable repair owner.

## Decision

- Receipt delivery persists the `sent` ledger transition first. Only after the
  effect group, successor dispatch, receipt projection and admin wakeup have
  been handled does `ReceiptDeliveryPipeline` enqueue
  `PinTelegramCredentialReceipt`; the pin is never called inline.
- `PinTelegramCredentialReceipt` runs on the dedicated supervised
  `telegram-pins` queue (`QueueTopology::PINS`). It does not share
  `telegram-receipts`, `notifications`, `default` or `telegram-outbound`, so a
  slow pin cannot delay successors, admin notices, checkout instructions or
  bot interactions.
- The job carries only the Order ID and effect ID. `TelegramCredentialPinService`
  reloads both, re-checks the unchanged eligibility rules (sent credential
  receipt, matching private customer chat, valid message ID, `contains_credentials`,
  no existing pointer) and never accepts a job as pin authority.
- One canonical pin per Order is enforced by an atomic claim
  (`telegram_credential_pin_claim_token`/`_claimed_at`, 60 s lease) before the
  Bot API call. Competing delivery/recovery workers cannot pin a second
  message. The pointer columns are written only after the validated Telegram
  response and only while the claim still matches and both pointer columns are
  null; a pointer is never moved or marked pinned without that response.
- A failed attempt releases its claim and leaves the Order unpinned. The
  existing `telegram:recover-outbound` sweep finds sent credential effects with
  no pointer, ordered by source and sequence, and retries the same eligibility
  and claim rules. Replaying recovery after success performs no Telegram call
  and does not move the pointer.
- An ambiguous/unknown delivery still dispatches no pin and is never blindly
  resent; the pin dispatch is downstream of the `sent` transition only.
- `telegram-pins` is a new supervised worker with `sleep 3`, `tries 3`,
  `timeout 60`, one process. It adds no second ledger, outbox, job state
  machine or ENV presentation setting.

## Consequences

- The credential pin may be delivered a few seconds after the receipt, bounded
  by worker pickup and the configured local transport. That is acceptable for
  optional convenience work and is exactly what the isolation intends.
- The app container runs one more PHP queue worker. The memory and topology
  impact is measured for the local smoke only; production capacity still
  requires the measured container-budget review recorded in the queue topology
  note.
- An operator sees no new customer-facing state. There is no new button, no
  changed eligibility threshold and no change to the 120 s admin unresolved
  threshold.
- Verification: `TelegramCredentialPinTest`,
  `TelegramCredentialPinPostgresConcurrencyTest`, `QueueConsumerTopologyTest`
  and the `pin-normal`/`pin-slow`/`pin-failed`/`pin-recovery` latency-baseline
  scenarios. Local evidence and command lines are in
  [the latency evidence](../telegram-latency-baseline-evidence.md#issue-652-pin-isolation).
  No production deploy, CI run or customer message is implied.
