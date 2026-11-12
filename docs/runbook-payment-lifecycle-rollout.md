# Payment lifecycle rollout runbook

## Expand and observe

Deploy the additive migrations before changing `PAYMENT_LIFECYCLE_ROLLOUT_MODE`. Existing workers remain compatible: lifecycle database transactions lock **Order, then Payment**, and KlikQRIS retains `klikqris:callback-status:{payment}` while Pakasir retains `pakasir:native-qris:create:{payment}`.

Set the mode to `shadow` (or `observe`) and run:

```sh
php artisan ops:payment-lifecycle-rollout-report
```

The scheduled five-minute report is read-only. Its legacy-stock observation reads at most 1,001 recipient IDs in ascending ID order: 1,000 candidates plus one lookahead, then checks ledger materialization only for those candidates. `legacy_stock_coverage` records the mode, candidate limit, candidates scanned, and whether coverage is complete. It does not rotate or accumulate coverage between runs.

When coverage is incomplete, `legacy_stock_unmaterialized` and `unresolved_total` are lower bounds, `unresolved_total_is_exact` is `false`, and `contract_safe` is always `false`. A partial zero means only that the observed candidates have ledger effects, not that all historical recipients do. Small recipient tables fully covered by the bounded observation retain exact counts and can be contract-safe when all queues are clear.

The Native QRIS lifecycle gate is active at the KlikQRIS and Pakasir create/recovery boundaries, and KlikQRIS also gates safe exposure and reconciliation. Use `canary` with `PAYMENT_LIFECYCLE_ROLLOUT_CANARY_PERCENTAGE` for a deterministic subset of Payments, then `enabled` after unresolved queues are clear. An incomplete bounded legacy-stock observation is not evidence that contract cleanup is safe. `rollback` stops new hardened-path selection; it does not delete data or reverse a paid transition.

## Reconcile before contract

Run the existing provider reconciliation commands and notification recovery until the report has no unresolved rows:

```sh
php artisan klikqris:backfill-in-flight --chunk=100
php artisan klikqris:reconcile --chunk=100
php artisan pakasir:reconcile --chunk=100
php artisan telegram:reconcile-stock-notifications --chunk=100
php artisan telegram:recover-outbound --queue=notifications
php artisan ops:payment-lifecycle-rollout-report --exact-legacy-stock --require-contract-safe
```

`klikqris:backfill-in-flight` is idempotent and only selects pending KlikQRIS Payments in `creating` or `outcome_unknown`. It recovers each one from provider status on the same Payment and never calls provider create; it continues to do so during rollback. The scheduler runs it every five minutes while KlikQRIS reconciliation is enabled.

`--require-contract-safe` fails closed on incomplete coverage; it never switches to an expensive exact query automatically. Before contract cleanup, run `--exact-legacy-stock` explicitly and unscheduled as above. This opt-in performs the full-history legacy-stock count and can be slow on large histories; do not add it to the five-minute schedule or run overlapping exact checks. It remains read-only and does not materialize effects or authorize Telegram delivery.

Investigate rather than discard `late_payment_queue`, `cancellation_attempts`, and terminal notification failures. Only remove legacy columns or workers in a separate contract migration after the final command succeeds and the PostgreSQL concurrency and end-to-end release gates pass.

Legacy KlikQRIS HTTP 404 bukan bukti unpaid. `klikqris:reconcile` menyimpan
next-check durable dan tetap exit 1 selama `unresolved_status_404>0`, termasuk
tick tanpa request. Jangan mengulang sweep atau mereset cursor untuk melewati
delay. Verifikasi merchant/environment/kredensial asli dengan provider dan
pertahankan unresolved money sampai ada evidence otoritatif. Ikuti
[prosedur diagnosis dan remediasi #663](klikqris-reconcile-candidate-classes.md#legacy-http-404-and-durable-discovery-663);
health heartbeat yang hijau tidak menutup task/payment failure.


## Alert response and rollback

Alert on a non-zero report count for stuck pending payments, provider uncertainty, Late Payment reviews, cancellation attempts, notification claims, or lifecycle mismatches. The report also logs incomplete legacy-stock coverage, including when the observed count is zero; this is missing contract evidence, not proof of undelivered notifications. Pause queue workers when a deployment must be contained, set `PAYMENT_LIFECYCLE_ROLLOUT_MODE=rollback`, deploy that configuration, then resume only the reconciliation workers needed to recover confirmed provider state. Never manually mark an uncertain payment paid or delete its lock/claim marker.
