# Receipt query/export work (#655)

Part of [#648](https://github.com/Alhiefikri/telegram-bot/issues/648), after the completed [#649 baseline](https://github.com/Alhiefikri/telegram-bot/issues/649). Measured locally on 2026-10-23. No production root cause or end-to-end latency/SLO claim.

## Measurement boundary

Before any optimization, the successful comparison run used source revision `e4c02ac51a85af6467abceea89160825f9fbb256`. The paired after run used the same fixtures and command with the #655 working-tree changes. Earlier harness-development runs (including a digest encoder that rejected PNG bytes and exercised fallback instead) are not comparison evidence.

Runtime: application image `telegram-bot:php-security-8.4.27`, PHP 8.4.27, PostgreSQL 16.15, Redis 7.4.11, Linux x86_64. Disposable containers `tb-655-pg` and `tb-655-redis` shared the internal Docker network `tb-655-profile`; no ports were published. PostgreSQL database: `receipt_profile_test`. Cache used this disposable Redis; queued wakeups were intercepted with `Queue::fake()` and were not processed by workers. Mail used `array`; logging used `null`. The actual `TelegramBotClient` was bound explicitly (non-PHPUnit testing otherwise selects the fake client), with an HTTP fixture at `https://telegram.fixture.test`, `Http::preventStrayRequests()` and an internal network blocking external access. No provider, customer or live Telegram endpoint was contacted.

A temporary `/tmp/receipt-profile-655.php`, mounted as `/profile.php`, bootstrapped the application and migrated only the guarded disposable database. It invoked the real owners:

1. `AdminPaidOrderOutcome::read($order->fresh())` before delivery.
2. `TelegramOutboundEffectService::recordReceipt($order->fresh())`, including preparation, eligibility and ledger writes.
3. Every ordered receipt child through `ReceiptDeliveryPipeline::deliver(..., dispatchNext: false, throwOnAmbiguous: true)`, including claims, eligibility, actual PNG/TXT generation, local HTTP transport, finalization and receipt-complete projection.
4. `AdminPaidOrderOutcome::read($order->fresh())` after delivery.

This is not a helper-only exporter benchmark. It is also not a Redis-worker, concurrency, provider-verification or payment-to-receipt latency test; those remain the #649/#656 runner's responsibility. Existing `TelegramReceiptTxtBenchmark` measures requested TXT downloads, not this automatic receipt materialization/delivery path, so it was intentionally unchanged.

### Dataset and collection

Faker seed `655`; application time fixed at `2026-10-23 12:00:00`. Factory-created paid/completed Orders had deterministic UUIDs derived from item-count/unit-count/format, `PROFILE-<items>-<units>-<format>` invoice numbers, IDR 10,000 total and a paid Payment at `2026-10-22 10:00:01`. Each item had its own Product, snapshot name `Product <&> <index>`, snapshot tutorial `Tutorial <&>`, no usage-guide URL, and the indicated allocated unit count. Synthetic legacy units used email/password-delimited text; structured units used `StructuredCredentialPayload::fromValues(..., CredentialAccessMethod::None)`. Product/Customer factories were seeded identically in both runs. No synthetic credential values were emitted.

One sample per path/dataset per revision: first legacy single-item path is process-cold, later paths share warmed presentation caches. Do not derive percentiles or stable speedup ratios from these samples. Additional after-run repetition is a smoke/resource check, not extra before/after samples.

`DB::listen` counted SQL without retaining bindings. A notifier subclass counted real `receiptPlan()` and `renderInvoiceUnit()` calls while delegating to the parent. Each exporter invocation was counted at its one `manual_fulfillment_reviews` authorization query, identified with an argument-free backtrace containing `TelegramPaidOrderTxtExporter::export`. CPU is user+system `getrusage()` delta, not wall time. Memory is PHP allocated peak (`memory_get_peak_usage(true)` after resetting each boundary), not total container RSS or native GD memory. The local HTTP fixture reduced each request's structure, strings and resource bytes to SHA-256 digests; it did not persist PNG, TXT, credentials or chat payloads.

Command (same for before and after; temporary harness removed after verification):

```sh
docker run --rm --network tb-655-profile \
  -v "$PWD/src:/var/www/html" \
  -v /tmp/receipt-profile-655.php:/profile.php:ro \
  -w /var/www/html --entrypoint php \
  -e APP_ENV=testing \
  -e APP_KEY=base64:QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE= \
  -e DB_CONNECTION=pgsql -e DB_HOST=tb-655-pg \
  -e DB_DATABASE=receipt_profile_test -e DB_USERNAME=profile -e DB_PASSWORD=profile \
  -e CACHE_STORE=redis -e REDIS_HOST=tb-655-redis \
  -e QUEUE_CONNECTION=redis -e SESSION_DRIVER=array -e LOG_CHANNEL=null \
  telegram-bot:php-security-8.4.27 /profile.php
```

## Paired results

Each pair is **before / after**. Item counts are distinct Order Items; units are per item. `L` means legacy credentials and `S` structured credentials. Delivery counts cover all receipt chunks, not just the document chunk.

| Dataset | Chunks | Admin SQL before delivery | Admin SQL after delivery | Delivery SQL | Delivery exports | Delivery CPU ms | Delivery PHP peak MiB |
| --- | ---: | --- | --- | --- | --- | --- | --- |
| 1 item × 1 unit L | 1 | 7 / 7 | 8 / 8 | 38 / 38 | 0 / 0 | 258.499 / 243.980 | 22 / 22 |
| 1 item × 1 unit S | 1 | 7 / 7 | 8 / 8 | 38 / 38 | 0 / 0 | 169.447 / 139.749 | 22 / 22 |
| 10 items × 2 units L | 2 | 16 / 7 | 17 / 8 | 66 / 64 | 3 / 2 | 229.122 / 209.094 | 22 / 22 |
| 10 items × 2 units S | 2 | 16 / 7 | 17 / 8 | 66 / 64 | 3 / 2 | 221.432 / 210.347 | 24 / 24 |
| 1 item × 60 units L | 2 | 7 / 7 | 8 / 8 | 66 / 64 | 3 / 2 | 245.211 / 222.697 | 24 / 24 |
| 1 item × 60 units S | 2 | 7 / 7 | 8 / 8 | 66 / 64 | 3 / 2 | 239.865 / 225.497 | 24 / 24 |
| 25 items × 20 units S | 2 | 31 / 7 | 32 / 8 | 66 / 64 | 3 / 2 | 542.213 / 492.341 | 26 / 26 |

Admin-before CPU ms, in dataset order: `20.394/18.768`, `6.068/4.447`, `12.703/6.794`, `13.020/8.314`, `5.382/6.117`, `6.144/6.447`, `20.825/9.318`. Its PHP peaks were unchanged in each pair (18 MiB for the cold first dataset, then 22/22/22/24/24/24 MiB). Counts, not noisy single-sample CPU differences, establish the optimization.

Preparation remains one plan and one PNG per order; SQL was unchanged at 20 for cold single legacy, 19 for single structured and 23 for every document dataset. Preparation exports remain zero for single caption receipts and one for document receipts. Delivery remains one plan/one PNG for caption receipts, two plans/one PNG for document receipts. All seven orders reached the receipt-complete marker; all 28 paired path results were equal. All 12 ordered Telegram request digests matched, including image/document bytes, captions, entity offsets and markup.

## Decisions and safety

- `AdminPaidOrderOutcome` uses collection `loadCount('stockItems')` on every read. It batches fresh counts without hydrating credentials and does not trust stale eager-loaded stock collections or previous count attributes. It preserves relation/global-scope semantics and loaded item ordering. The completion/intervention rules and `ESCALATION_SECONDS = 120` are unchanged.
- Automatic document planning already performs and validates a full export. Keep that bounded `TelegramDocumentPayload` on the transient `TelegramReceiptPlan`, and send the same payload in the same document attempt rather than exporting it again. This removes one export and its two authorization queries per automatic document delivery. The first summary attempt still validates its own export; the subsequent document attempt still independently reloads/reauthorizes. No formatter, escaping, grouping, quantity, numbering or transport option changed.
- Do **not** reuse PNGs or plans between materialization and a queued child, between chunks, or between retries. The measured duplicate PNG work remains deliberately unchanged: delivery is a separately claimed attempt against reloaded eligibility. Carrying plaintext/image state across that handoff would require persistent caching or a wider protocol change. There is no claim that PNG work was accelerated.
- The retained document is at most the existing 1,048,576-byte bound. Plans/payloads refuse serialization and hide contents in debug output. No static/service memo, new cache, queue field, ledger field, ENV setting, routing change or second outbox was introduced. Existing order hydration/export construction still scales with the order; this change does not claim constant-memory streaming for arbitrarily large orders.
- The retired-credential continuation regression proves a summary attempt cannot authorize a later document from a stale plan. The allocation regression proves repeated reads refresh counts even when stock collections/counts were loaded earlier, and checks the 119/120-second unresolved boundary.

Framework reference: Context7 `/laravel/docs` (13.x), [deferred relationship count loading](https://github.com/laravel/docs/blob/13.x/eloquent-relationships.md#deferred-count-loading). Library resolution and focused `loadCount` documentation were retrieved through Context7's public API because a Context7 MCP route was not mounted in this session.

## Additional resource and regression checks

After the paired run, 30 fresh plans for the 25-item/500-unit Order were built
and released in one process. Allocated PHP memory sampled after attempts
5/10/15/20/25/30 was exactly 27,262,976 bytes (26 MiB) each time. This establishes
no accumulating transient-plan retention in this bounded smoke; it is not a
native-memory, unlimited-order or long-lived production-worker guarantee.

Network-disabled PHP 8.4.27 PHPUnit runs:

```sh
docker run --rm --network none -v "$PWD/src:/var/www/html" \
  -w /var/www/html --entrypoint php -e APP_ENV=testing \
  -e APP_KEY=base64:QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE= \
  telegram-bot:php-security-8.4.27 vendor/bin/phpunit \
  tests/Feature/Telegram/TelegramReceiptOutboundEffectTest.php \
  tests/Feature/Telegram/TelegramLifecycleOutboundEffectTest.php \
  tests/Feature/Telegram/TelegramReceiptActionPanelTest.php \
  tests/Feature/Telegram/TelegramPaidOrderTxtExporterTest.php \
  tests/Unit/Services/Telegram/TelegramAdminPaidOrderNotifierTest.php \
  tests/Unit/Services/Telegram/TelegramReceiptPlanTest.php
```

Result: **151 tests / 1,382 assertions passed**. This run included the new
retired-credential continuation and expanded document-plan secrecy checks.
After adding the current-allocation/119–120-second regression, the receipt
suite plus `tests/Feature/SupplierIndependentRegressionTest.php` passed
**108 tests / 1,222 assertions**. These runs overlap; do not add their counts.
The suites use isolated SQLite per `phpunit.xml`; PostgreSQL evidence is the
runtime-owner smoke above, not a new concurrency qualification.

Final targeted edge/security rerun after import-style cleanup passed
**3 tests / 16 assertions**; targeted Pint passed all five changed PHP files.

No CI/CD, deploy, production access, real payment, or customer message was
performed. Disposable database/Redis containers and the temporary profiler
were removed after verification; no secret-bearing artifact was retained.
