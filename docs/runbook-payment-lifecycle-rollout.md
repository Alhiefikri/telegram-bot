# Payment lifecycle rollout runbook

## Expand and observe

Deploy the additive migrations before changing `PAYMENT_LIFECYCLE_ROLLOUT_MODE`. Existing workers remain compatible: lifecycle database transactions lock **Order, then Payment**, and KlikQRIS retains `klikqris:callback-status:{payment}` while Pakasir retains `pakasir:native-qris:create:{payment}`.

Set the mode to `shadow` (or `observe`) and run:

```sh
php artisan ops:payment-lifecycle-rollout-report
```

Use `canary` with `PAYMENT_LIFECYCLE_ROLLOUT_CANARY_PERCENTAGE` for a deterministic subset of Payments, then `enabled` after the report is clean. `rollback` stops selecting the hardened path; it does not delete data or reverse a paid transition.

## Reconcile before contract

Run the existing provider reconciliation commands and notification recovery until the report has no unresolved rows:

```sh
php artisan klikqris:reconcile --chunk=100
php artisan pakasir:reconcile --chunk=100
php artisan telegram:reconcile-stock-notifications --chunk=100
php artisan telegram:recover-outbound --queue=notifications
php artisan ops:payment-lifecycle-rollout-report --require-contract-safe
```

Investigate rather than discard `late_payment_queue`, `cancellation_attempts`, and terminal notification failures. Only remove legacy columns or workers in a separate contract migration after the final command succeeds and the PostgreSQL concurrency and end-to-end release gates pass.

## Alert response and rollback

Alert on a non-zero report count for stuck pending payments, provider uncertainty, Late Payment reviews, cancellation attempts, notification claims, or lifecycle mismatches. Pause queue workers when a deployment must be contained, set `PAYMENT_LIFECYCLE_ROLLOUT_MODE=rollback`, deploy that configuration, then resume only the reconciliation workers needed to recover confirmed provider state. Never manually mark an uncertain payment paid or delete its lock/claim marker.
