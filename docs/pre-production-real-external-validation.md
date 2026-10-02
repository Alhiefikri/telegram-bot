# Real External Pre-Production Validation

Use this runbook when a release needs proof against live external services. The synthetic Docker gate covers the app's production-like Docker/Nginx/PostgreSQL/Supervisor topology; this validation covers the parts that cannot be faked safely: Telegram delivery, real Telegram identities, the Store Channel, and optional legacy Pakasir settlement verification.

Do not commit or paste secrets, private chat IDs, private user IDs, Telegram file IDs, Pakasir API keys, or private URLs into reports. Use configured/missing flags, redacted correlation IDs, and pass/fail notes instead.

## Prerequisite Checklist

- Public HTTPS `APP_URL` that routes to the dogfood Docker web service.
- `APP_KEY`, `POSTGRES_PASSWORD`, `TELEGRAM_BOT_TOKEN`, and `TELEGRAM_WEBHOOK_SECRET`.
- `TELEGRAM_ADMIN_CHAT_ID` and `TELEGRAM_ADMIN_USER_ID` for a disposable admin operator.
- Test buyer Telegram user that is safe to message.
- Disposable Store Channel where the bot is an admin and can post.
- Store Channel fixture values:
  - `DOGFOOD_STORE_CHANNEL_CHAT_ID`
  - `DOGFOOD_STORE_CHANNEL_USERNAME`
  - `DOGFOOD_STORE_CHANNEL_LINK`
  - `DOGFOOD_STORE_CHANNEL_MEMBER_USER_ID`
  - `DOGFOOD_STORE_CHANNEL_NON_MEMBER_USER_ID`
- `DOGFOOD_TELEGRAM_PHOTO_FILE_ID` from a real proof-photo message to the dogfood bot.
- `DOGFOOD_TELEGRAM_CALLBACK_QUERY_ID` from a fresh real callback tap, only for the immediate run where callback-answer is being measured.
- Dogfood admin web credentials:
  - `DOGFOOD_ADMIN_EMAIL`
  - `DOGFOOD_ADMIN_PASSWORD`

## Secret Output Guard

Use `./scripts/safe-compose-config.sh --project <dogfood-project> --env-file <env-file>` when you need Compose validation for a production-like run. Do not capture or paste plain `docker compose ... config` output; it expands `APP_KEY`, Telegram, Pakasir, and database values into the artifact stream.

## Safe Preflight

Run these checks before any flow that sends real Telegram messages:

```bash
./scripts/dogfood-volume-preflight.sh --project telegram-bot-dogfood-issue74

APP_URL="$APP_URL" php -r '
$url = rtrim(getenv("APP_URL"), "/");
$ch = curl_init($url."/up");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
echo json_encode(["https" => str_starts_with($url, "https://"), "status" => $status, "up" => $status >= 200 && $status < 300, "body_bytes" => strlen((string) $body)], JSON_PRETTY_PRINT).PHP_EOL;
'
```

Verify the webhook target without printing the token or private URL:

```bash
php -r '
$token = getenv("TELEGRAM_BOT_TOKEN");
$target = rtrim(getenv("APP_URL"), "/")."/api/webhook/telegram";
$apiBase = "https://api.telegram.org";
$json = json_decode(file_get_contents($apiBase."/bot".$token."/getWebhookInfo"), true);
$url = (string) ($json["result"]["url"] ?? "");
echo json_encode([
    "telegram_ok" => ($json["ok"] ?? false) === true,
    "target_https" => str_starts_with($target, "https://"),
    "webhook_url_configured" => $url !== "",
    "webhook_matches_app_url" => hash_equals($target, $url),
    "pending_update_count" => $json["result"]["pending_update_count"] ?? null,
    "allowed_updates" => $json["result"]["allowed_updates"] ?? null,
    "last_error_present" => isset($json["result"]["last_error_message"]),
], JSON_PRETTY_PRINT).PHP_EOL;
'
```

Only set or reset the Telegram webhook after confirming the bot and public URL are disposable:

```bash
docker compose -p telegram-bot-dogfood-issue74 exec -T app php artisan telegram:webhook:set
```

## Real Flow Matrix

Record pass/fail for each row in the run report.

| Area | Scenario |
| --- | --- |
| Telegram webhook | Real test buyer sends `/start`; `telegram_updates` receives the update through the public HTTPS webhook and the queue drains. |
| Buyer Telegram | `/start`, `/menu`, catalog browse, product detail, `/cari keyword`, add to cart, checkout, `/status` or `/pesanan`, `/batal`, empty-cart checkout/cancel behavior, and payment proof photo forwarding with `/payment INVOICE`. |
| Admin Telegram | Admin dashboard button visibility, checkout notification to admin, `/broadcast ...` only when scoped to disposable recipients, Store Channel test send, Sales Proof Post publish, and Sales Proof Post retry when in release scope. |
| Store Channel | `/start` menampilkan CTA channel yang dapat dibuka tanpa membership check; Store Channel test send dan Sales Proof Post tetap memakai target yang sudah diuji. |
| Pakasir legacy settlement | If the compatibility lane is in release scope, run reconcile/webhook/payment-detail only against an existing historical Payment fixture; never create a new Pakasir checkout. |
| Supplier API Reseller | Only with separate explicit spending approval: enter a rotated key through Owner/Admin, verify currency/equivalent SKU, authorize one controlled funded order, verify actual debit/quantity/retrieval and customer-owned delivery, and assess duplicate safety only for documented idempotent providers. Never use heuristic name/time matching or buy again after ambiguity. |
| Final operations | `jobs` queue depth is `0`, `failed_jobs` is `0`, and Docker logs show no unexplained Telegram/Pakasir errors from the run window. |

Supplier development verification uses disposable databases and fake provider HTTP; it proves application behavior, not a live supplier debit, warranty, resale rights or delivery validity. Follow the [supplier runbook](runbook-supplier-api-reseller.md) before enabling production mappings. No supplier purchase or funding is authorized by running the ordinary dogfood harness.

## Harness Commands

The broad dogfood harness may be used after the manual real-account checks, with fake outbound disabled and all disposable fixtures configured:

```bash
mkdir -p artifacts/real-external-validation/issue-74-$(date -u +%Y%m%dT%H%M%SZ)

docker compose -p telegram-bot-dogfood-issue74 exec -T --user laravel \
  -e DOGFOOD_FAKE_TELEGRAM_OUTBOUND=false \
  app php scripts/perf-dogfood.php \
  > artifacts/real-external-validation/issue-74-<timestamp>/perf-dogfood.json \
  2> artifacts/real-external-validation/issue-74-<timestamp>/perf-dogfood.stderr
```

Finish with:

```bash
docker compose -p telegram-bot-dogfood-issue74 exec -T app php artisan ops:health --db --queue-schema --supervisor
docker compose -p telegram-bot-dogfood-issue74 exec -T app php artisan ops:check-failed-jobs --max=0
docker compose -p telegram-bot-dogfood-issue74 exec -T app php artisan ops:check-queue-depth --include-reserved-and-delayed --max=0
```
