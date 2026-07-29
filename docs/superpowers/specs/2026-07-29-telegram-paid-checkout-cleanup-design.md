# Telegram Paid Checkout Cleanup Design

**Date:** 2026-07-29
**Status:** Approved for planning
**Scope:** Remove stale buyer-facing KlikQRIS checkout and order-detail messages after successful receipt delivery.

## Goal

After a KlikQRIS payment is authoritatively confirmed and the buyer's receipt or
product delivery succeeds, remove the obsolete pending-payment messages from the
buyer chat. Keep receipts, delivered products, and failure messages visible.

## Tracked Messages

The application tracks two independent buyer messages per order:

- The primary KlikQRIS QR checkout instruction, including its image and caption.
- The latest order-detail message produced by the buyer's status/detail action.

Tracking stores chat ID and Telegram message ID. Sending a newer order-detail
message replaces the tracked detail target. Tracking must occur only after
Telegram returns a successful response with a numeric message ID.

## Cleanup Trigger

Cleanup is dispatched only after receipt or product delivery has completed
successfully. A paid transition alone does not remove buyer context. Existing
late-payment, fulfillment, and receipt idempotency rules remain authoritative.

The cleanup job runs on the `notifications` queue after the successful delivery
transaction commits. It handles both tracked targets independently: failure to
delete one target does not prevent an attempt for the other target.

## Idempotency And Recovery

Deletion uses a durable per-order claim and records completion separately for
the QR checkout message and order-detail message. A repeated job never deletes
an unrelated newer message and never re-dispatches the receipt.

Telegram `message not found` and `message cannot be deleted` responses are
treated as terminal cleanup outcomes because the stale message is either gone
or cannot be changed. Temporary transport and Telegram server failures release
the claim for retry. The existing outbound recovery command must redispatch due
cleanup work after worker interruption.

## Provider Boundary

The cleanup interface is provider-neutral, but this change wires KlikQRIS first.
Existing Binance cleanup behavior must remain unchanged or be migrated to the
same generic implementation with equivalent tests. Pakasir and manual checkout
messages are not silently added to cleanup scope.

## Data And Security

Schema changes are additive. Only Telegram chat/message identifiers and cleanup
state are stored; no KlikQRIS API key, merchant credential, signature, raw QR
image, or webhook payload is added. Logs use existing redaction and correlation
patterns.

## Validation

Automated tests cover:

1. KlikQRIS QR send persists its returned message ID.
2. Order-detail send persists its returned message ID.
3. Paid status without successful receipt does not delete either message.
4. Successful receipt dispatches one cleanup job.
5. Cleanup deletes both tracked messages and records completion.
6. One missing message does not block cleanup of the other.
7. A newer tracked detail message is not deleted by a stale claim.
8. Retry and recovery remain idempotent.
9. Receipt, product delivery, and Pakasir/Binance behavior do not regress.

Real sandbox validation creates a fresh KlikQRIS checkout, opens order detail,
simulates payment without pressing the buyer status button again, observes the
automatic receipt, and confirms both pending messages disappear while the
receipt remains visible.
