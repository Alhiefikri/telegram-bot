# Issue 74 Real External Validation Report

Date: 2026-07-02

Status: **blocked / not passed**.

No webhook mutation was performed, and no real Telegram outbound flow was executed. The configured credentials were not proven disposable, and the required test buyer, Store Channel, member/non-member identities, proof-photo, callback-query, dogfood admin credentials, and Pakasir pending transaction fixtures were not configured.

## Read-Only Checks Performed

| Check | Result | Notes |
| --- | --- | --- |
| Fixture availability | Failed | Required `DOGFOOD_*` fixtures were missing for buyer proof photo, Store Channel, member/non-member gate, callback answer, dogfood admin login, and Pakasir pending transaction. |
| Public HTTPS `APP_URL` health | Failed | `APP_URL` is HTTPS, but `GET $APP_URL/up` returned HTTP `404`. |
| Telegram webhook info | Partial pass | Telegram `getWebhookInfo` returned `ok=true`; configured webhook URL matches `$APP_URL/api/webhook/telegram`; allowed updates are `message` and `callback_query`. `pending_update_count=2` and `last_error_present=true`, so this is not clean. |
| Docker runtime health | Passed | `ops:health --db --queue-schema --supervisor` returned DB, queue schema, and Supervisor OK. |
| Final queue depth | Passed | `jobs_depth=0`. |
| Failed jobs | Failed | `ops:check-failed-jobs --max=0` reported `failed_jobs=8`. |
| Docker logs, last 30 minutes | Partial pass | No Telegram/Pakasir error lines were visible in the sampled redacted logs, but the existing failed jobs make the final operational state unclean. |
| Runtime command availability | Failed | Current running Docker image does not expose `telegram:webhook:set`; source-local `src` does. Rebuild the dogfood image before the real run. |
| Pakasir scope | Out of scope for this run | `TELEGRAM_PAYMENT_MODE=manual`; Pakasir fixtures are still missing if the release switches Pakasir on. |

## Scenario Status

| Scenario | Status |
| --- | --- |
| Prerequisite checklist | Failed: fixture values missing. |
| Real Telegram webhook setup against public HTTPS URL | Partial: existing Telegram webhook matches the configured HTTPS target, but public `/up` failed and Telegram reports a previous error. |
| Real buyer Telegram flows | Not run: missing disposable test buyer and proof-photo fixture; unsafe to message current configured chat. |
| Real admin-facing Telegram flows | Not run: missing dogfood admin web credentials and disposable recipient scope; unsafe to run broadcast/admin notifications. |
| Store Channel gate member/non-member | Not run: missing Store Channel username fixture and member/non-member Telegram identities. |
| Store Channel test send | Not run: missing explicit disposable Store Channel env fixture. |
| Sales Proof Post publish/retry | Not run: missing explicit disposable Store Channel fixture. |
| Pakasir reconcile/webhook/payment detail | Out of scope for manual payment mode; blocked if Pakasir is included later because pending transaction fixture is missing. |
| Final operational state | Failed: `jobs_depth=0`, `failed_jobs=8`. |

## Commands Used

The commands below are the redacted command forms used for this report. They read configured values locally and print only status, counts, or redacted output.

```bash
docker compose ps --format json
```

```bash
php -r '$env = parse_ini_file(".env", false, INI_SCANNER_RAW); $url = rtrim((string)($env["APP_URL"] ?? ""), "/"); if ($url === "") { echo json_encode(["app_url_configured"=>false]).PHP_EOL; exit(2); } $ch = curl_init($url."/up"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_NOBODY=>false, CURLOPT_TIMEOUT=>15, CURLOPT_FOLLOWLOCATION=>false]); $body = curl_exec($ch); $err = curl_error($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); echo json_encode(["app_url_configured"=>true, "app_url_https"=>str_starts_with($url, "https://"), "up_status"=>$status, "body_is_up"=>trim((string)$body)==="OK" || trim((string)$body)==="up", "error"=>$err ?: null], JSON_PRETTY_PRINT).PHP_EOL;'
```

```bash
php -r '$env = parse_ini_file(".env", false, INI_SCANNER_RAW); $token = trim((string)($env["TELEGRAM_BOT_TOKEN"] ?? "")); $appUrl = rtrim(trim((string)($env["APP_URL"] ?? "")), "/"); $apiBase = rtrim(trim((string)($env["TELEGRAM_API_BASE_URL"] ?? "https://api.telegram.org")), "/"); if ($token === "" || $appUrl === "") { echo json_encode(["token_configured"=>$token!=="", "app_url_configured"=>$appUrl!==""]).PHP_EOL; exit(2); } $target = $appUrl."/api/webhook/telegram"; $ch = curl_init($apiBase."/bot".$token."/getWebhookInfo"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20]); $body = curl_exec($ch); $err = curl_error($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); $json = json_decode((string)$body, true); $webhookUrl = (string)($json["result"]["url"] ?? ""); echo json_encode(["http_status"=>$status, "telegram_ok"=>($json["ok"] ?? false) === true, "target_https"=>str_starts_with($target, "https://"), "webhook_url_configured"=>$webhookUrl !== "", "webhook_matches_app_url"=>hash_equals($target, $webhookUrl), "pending_update_count"=>$json["result"]["pending_update_count"] ?? null, "allowed_updates"=>$json["result"]["allowed_updates"] ?? null, "last_error_present"=>isset($json["result"]["last_error_message"]), "error"=>$err ?: null], JSON_PRETTY_PRINT).PHP_EOL;'
```

```bash
docker compose exec -T app php artisan ops:health --db --queue-schema --supervisor
docker compose exec -T app php artisan ops:check-failed-jobs --max=0
docker compose exec -T app php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $db = $app["db"]; echo json_encode(["jobs_depth" => $db->table("jobs")->count(), "failed_jobs" => $db->table("failed_jobs")->count()], JSON_PRETTY_PRINT).PHP_EOL;'
docker compose logs --since=30m app scheduler web 2>&1 | tail -n 240 | sed -E 's#bot[0-9A-Za-z:_-]+#bot<redacted>#g; s#(TELEGRAM_BOT_TOKEN=)[^[:space:]]+#\1<redacted>#g; s#(TELEGRAM_WEBHOOK_SECRET=)[^[:space:]]+#\1<redacted>#g; s#(PAKASIR_API_KEY=)[^[:space:]]+#\1<redacted>#g; s#https://[^[:space:]]+#https://<redacted>#g'
cd src && php artisan telegram:webhook:set --dry-run | sed -E 's#https://.*#/api/webhook/telegram <redacted-public-https-url>#'
docker compose exec -T app php artisan list --raw | rg '^telegram|webhook|pakasir|ops'
```

## Next Required Human Inputs

Before this issue can pass, provide disposable real fixture values for the runbook in [pre-production-real-external-validation.md](../pre-production-real-external-validation.md), rebuild the dogfood Docker image from the current source, and clear or explain the existing eight failed jobs in the dogfood database.
