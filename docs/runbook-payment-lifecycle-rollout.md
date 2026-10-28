# Payment lifecycle rollout runbook

## Expand and observe

Deploy the additive migrations before changing `PAYMENT_LIFECYCLE_ROLLOUT_MODE`. Existing workers remain compatible: lifecycle database transactions lock **Order, then Payment**, and KlikQRIS retains `klikqris:callback-status:{payment}` while Pakasir retains `pakasir:native-qris:create:{payment}`.

Set the mode to `shadow` (or `observe`) and run:

```sh
php artisan ops:payment-lifecycle-rollout-report
```

The Native QRIS lifecycle gate is active at the KlikQRIS and Pakasir create/recovery boundaries, and KlikQRIS also gates safe exposure and reconciliation. Use `canary` with `PAYMENT_LIFECYCLE_ROLLOUT_CANARY_PERCENTAGE` for a deterministic subset of Payments, then `enabled` after the report is clean. `rollback` stops new hardened-path selection; it does not delete data or reverse a paid transition.

## Reconcile before contract

Run the existing provider reconciliation commands and notification recovery until the report has no unresolved rows:

```sh
php artisan klikqris:backfill-in-flight --chunk=100
php artisan klikqris:reconcile --chunk=100
php artisan pakasir:reconcile --chunk=100
php artisan telegram:reconcile-stock-notifications --chunk=100
php artisan telegram:recover-outbound --queue=notifications
php artisan ops:payment-lifecycle-rollout-report --require-contract-safe
```

`klikqris:backfill-in-flight` is idempotent and only selects pending KlikQRIS Payments in `creating` or `outcome_unknown`. It recovers each one from provider status on the same Payment and never calls provider create; it continues to do so during rollback. The scheduler runs it every five minutes while KlikQRIS reconciliation is enabled.

Investigate rather than discard `late_payment_queue`, `cancellation_attempts`, and terminal notification failures. Only remove legacy columns or workers in a separate contract migration after the final command succeeds and the PostgreSQL concurrency and end-to-end release gates pass.

Legacy KlikQRIS HTTP 404 bukan bukti unpaid. `klikqris:reconcile` menyimpan
next-check durable dan tetap exit 1 selama `unresolved_status_404>0`, termasuk
tick tanpa request. Jangan mengulang sweep atau mereset cursor untuk melewati
delay. Verifikasi merchant/environment/kredensial asli dengan provider dan
pertahankan unresolved money sampai ada evidence otoritatif. Ikuti
[prosedur diagnosis dan remediasi #663](klikqris-reconcile-candidate-classes.md#legacy-http-404-and-durable-discovery-663);
health heartbeat yang hijau tidak menutup task/payment failure.


## Alert response and rollback

Alert on a non-zero report count for stuck pending payments, provider uncertainty, Late Payment reviews, cancellation attempts, notification claims, or lifecycle mismatches. Pause queue workers when a deployment must be contained, set `PAYMENT_LIFECYCLE_ROLLOUT_MODE=rollback`, deploy that configuration, then resume only the reconciliation workers needed to recover confirmed provider state. Never manually mark an uncertain payment paid or delete its lock/claim marker.
