# Telegram receipt TXT observability and benchmark

The TXT/action-panel path emits structured DogfoodTiming events only when
the existing dogfood timing switch is enabled. No new metrics backend,
database table, queue, or environment setting is used.

## Benchmark

Run the synthetic benchmark from src/ in the production-equivalent Docker
application container:

    php artisan telegram:benchmark-receipt-txt --json

The command creates encrypted synthetic credentials for 1, 5, 20, and 50
items, runs the exporter and notifications delivery path, prints a
redacted JSON report, and rolls the fixture transaction back before exiting.
The 50-item case is represented as three Order Items of at most 20 each, which
matches the current quantity constraint.

The report contains only:

- bounded query count and query timing;
- TXT render duration;
- output byte count and the 1 MiB application limit;
- worker RSS before/after render and delta;
- queue wait;
- fake Telegram sendDocument duration;
- effect completion duration/state;
- opaque effect correlation and pass/fail thresholds.

It must not contain document text, email, password, Auth Token, OTP URL,
Product delivery note, or a document hash. A failed threshold is a release
signal; the harness does not truncate output or retry an ambiguous document.

The first 1-item sample establishes the query/render/timing baseline. The
release gate then requires a bounded query count for every larger fixture,
output at or below 1 MiB, and measured values below the baseline-derived
thresholds shown in the report. Re-run with the same production-like
PHP/PostgreSQL/worker limits when comparing releases.

## Staging smoke

Use a disposable staging bot/chat and synthetic credentials:

1. Create one paid eligible Order with ten items and let the protected receipt complete.
2. Confirm the action panel is below the final receipt chunk and the receipt remains protected.
3. Press TXT and verify the confirmation contains only Product/order/item summary and the privacy warning.
4. Press Cancel and verify no sendDocument request or TXT effect is created.
5. Press TXT again, then Send file; verify one .txt document arrives, downloads, and opens on current Android and iOS Telegram clients.
6. Verify the document contains synthetic credential fields, while panel, confirmation, effect metadata, queue payload, and logs do not.
7. Replay the same Send File callback and verify it is stale with no second effect or document.
8. Exercise an ambiguous transport/worker interruption and verify unknown/manual review without automatic resend.

## Production observation window

Review only these safe fields from the existing log channel and outbound effect review screen:

- metric name and count;
- effect/order/customer/callback correlations;
- action-panel or TXT outcome;
- bounded authorization/rate-limit category;
- Product/item count bucket;
- output-size, render, queue-wait, Telegram-duration, and effect-completion buckets;
- DB query count and worker RSS measurements;
- bounded exception class, HTTP status, and effect error code.

Do not export or search raw Telegram request/response bodies, rendered files,
credential projections, Product notes, customer issue text, or document hashes.
Panel failure is independent of the paid receipt; an unknown TXT delivery
requires operator review and must not be blindly resent.
