# Task 3 Report: KlikQRIS sandbox HTTP client

## Commit

- SHA: 104605bfa7bee11db4e0496be24b1236989a6a66
- Message: `feat: add KlikQRIS sandbox client`

## Files changed

- `src/app/Services/KlikQris/KlikQrisProviderAdapter.php`
- `src/app/Services/KlikQris/KlikQrisClient.php`
- `src/app/Providers/AppServiceProvider.php`
- `src/tests/Feature/KlikQrisGatewayTest.php`
- `src/tests/Support/KlikQrisSandboxScenarios.php`

## RED

Command:

```bash
cd src && php artisan test tests/Feature/KlikQrisGatewayTest.php
```

Observed RED result before production client/binding code:

- `Tests\Feature\KlikQrisGatewayTest` failed.
- Failure reason: `Target class [App\Services\KlikQris\KlikQrisProviderAdapter] does not exist.`
- This matched the expected Task 3 failure because the adapter interface, client, and service-container binding had not been implemented.

## GREEN

Implemented the minimal sandbox client surface required by Task 3:

- Added `KlikQrisProviderAdapter` interface with `createTransaction(Payment $payment)` and `transactionStatus(Payment $payment)`.
- Added `KlikQrisClient` bound to the adapter in `AppServiceProvider::register()`.
- `createTransaction()`:
  - Calls sandbox readiness before any HTTP request.
  - Sends exactly one `POST` to `/qris/create`.
  - Uses JSON request body with `order_id`, `id_merchant`, `amount`, and `keterangan`.
  - Uses `x-api-key` and `id_merchant` headers.
  - Does not retry connection, request, or HTTP failures.
  - Parses with `KlikQrisTransaction::fromCreatePayload()`.
  - Calls `assertMatches($payment)`.
- `transactionStatus()`:
  - Calls sandbox readiness before any HTTP request.
  - Sends `GET /qris/status/{rawurlencoded-order-id}`.
  - Keeps credentials out of query parameters.
  - Parses with `KlikQrisTransaction::fromStatusPayload()`.
  - Calls `assertMatches($payment)`.
  - Retries only connection errors and HTTP 408, 429, or 5xx.
  - Does not retry HTTP 401 or other non-retryable 4xx.
  - Uses configured `status_max_attempts` and capped exponential delay from `status_retry_delay_ms`.
- Exceptions are sanitized and include only operation/status/class context, not API key, merchant ID, signature, authorization, response body, or other secret values.

Targeted GREEN command:

```bash
cd src && php artisan test tests/Feature/KlikQrisGatewayTest.php
```

Final targeted result:

- 12 passed, 36 assertions.

## Regressions

Commands:

```bash
cd src && php artisan test tests/Unit/Services/KlikQris/KlikQrisProviderReadinessTest.php tests/Unit/Services/KlikQris/KlikQrisTransactionTest.php tests/Feature/KlikQrisGatewayTest.php tests/Feature/PakasirGatewayTest.php
cd src && ./vendor/bin/pint app/Services/KlikQris/KlikQrisProviderAdapter.php app/Services/KlikQris/KlikQrisClient.php app/Providers/AppServiceProvider.php tests/Feature/KlikQrisGatewayTest.php tests/Support/KlikQrisSandboxScenarios.php
cd src && php artisan test tests/Unit/Services/KlikQris/KlikQrisProviderReadinessTest.php tests/Unit/Services/KlikQris/KlikQrisTransactionTest.php tests/Feature/KlikQrisGatewayTest.php tests/Feature/PakasirGatewayTest.php
```

Results:

- Focused tests before Pint: 68 passed, 297 assertions.
- Pint: 5 files checked, 2 style issues fixed.
- Focused tests after Pint: 68 passed, 297 assertions.

## Self-review

- Sandbox only. No production URL, production switch, checkout route change, or Pakasir behavior change was added.
- Tests use only fake credential values such as `test-only-key`, `merchant-test`, and `opaque-test-signature`.
- No real credentials were read, copied, printed, or stored.
- Create POST is not wrapped in Laravel retry and sends only one attempt on connection failure.
- Status retries only the allowed transient failure set.
- Readiness failure is tested to happen before any HTTP request.
- Request contract tests cover headers, JSON body, path, and rawurlencoded order ID.
- Parser context is explicit through `fromCreatePayload()` and `fromStatusPayload()`.
- Sanitized exception tests cover invalid JSON and mismatched provider payloads.

## Concerns

- The capped exponential status delay uses a hard cap of 1000ms because Task 3 config currently provides `status_retry_delay_ms` but no separate max-delay config key.
- The client currently returns normalized transactions only. Persistence/idempotency and provider event logging remain intentionally deferred to later tasks in the plan.
