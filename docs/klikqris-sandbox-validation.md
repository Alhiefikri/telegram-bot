# KlikQRIS Sandbox Validation Runbook

This runbook validates KlikQRIS sandbox checkout behavior only. Passing this sandbox runbook does not authorize production enablement, production credentials, production traffic, or a production deployment.

## 1. Confirm worktree and branch

Run from the feature worktree for the sandbox integration:

```bash
git status --short --branch
```

Expected operator checks:

- Worktree path is the intended sandbox validation worktree.
- Branch is the intended non-production feature branch.
- No production deploy workflow is being triggered by this runbook.

## 2. Required environment variable names

Confirm the non-production `.env` contains values for these labels only. Do not print, paste, screenshot, or commit the values.

- `APP_ENV`
- `APP_URL`
- `APP_KEY`
- `TELEGRAM_BOT_TOKEN`
- `KLIKQRIS_SANDBOX_ENABLED`
- `KLIKQRIS_SANDBOX_BASE_URL`
- `KLIKQRIS_SANDBOX_API_KEY`
- `KLIKQRIS_SANDBOX_MERCHANT_ID`
- `KLIKQRIS_QRIS_TTL_MINUTES`
- `KLIKQRIS_SANDBOX_WEBHOOK_SECRET`

## 3. Confirm the Telegram bot is the testing bot

Before refreshing Docker or creating a checkout, confirm the configured Telegram bot username is the LhieStore Testing Bot without printing the token.

Safe confirmation pattern:

1. Read only the token label from `.env`, not the value.
2. Use an operator-approved local secret lookup or Telegram bot metadata check that does not echo `TELEGRAM_BOT_TOKEN`.
3. Record only the observed bot username and timestamp in the evidence table.

Required result: the bot username is the testing bot. If the bot is not the testing bot, stop.

## 4. Confirm non-production environment

Before any local Docker refresh, confirm:

- `APP_ENV` is not `production`.
- The configured bot is the LhieStore Testing Bot.
- KlikQRIS variables are sandbox labels, not production labels.
- This runbook is not being used as production authorization.

If any check is uncertain, stop.

## 5. Cloudflare Tunnel callback URL pattern

Use a temporary non-production Cloudflare Tunnel URL for sandbox callbacks.

Expected callback URL pattern:

```text
https://<temporary-non-production-tunnel-host>/api/webhook/klikqris/sandbox
```

Do not paste tunnel credentials or private dashboard material into evidence.

## 6. KlikQRIS dashboard settings

In the KlikQRIS sandbox dashboard, configure:

- Callback URL: the Cloudflare Tunnel callback URL from this runbook.
- QRIS expiry: 15 minutes.
- Sandbox merchant/account only.

Record only labels, timestamps, and pass/fail observations.

## 7. Refresh local Docker only after gates pass

Do not execute these commands until `.env` has been verified as non-production and points to the LhieStore Testing Bot.

```bash
docker compose -p telegram-bot-prod up -d --build --remove-orphans
docker compose -p telegram-bot-prod ps
```

Then run these exact health checks:

```bash
docker compose -p telegram-bot-prod exec -T app php artisan ops:health --db --queue-schema --queue-depth --supervisor
docker compose -p telegram-bot-prod exec -T scheduler php artisan ops:health --scheduler-heartbeat=180
```

If any command shows production environment, production bot, or unexpected stack/project names, stop.

## 8. Create checkout in Telegram

Using only the LhieStore Testing Bot:

1. Start a new disposable cart.
2. Choose KlikQRIS sandbox checkout.
3. Confirm a QRIS checkout is created.
4. Capture the payment UUID or merchant reference for local validation.

Run local validation with labels-only output:

```bash
docker compose -p telegram-bot-prod exec -T app php artisan klikqris:validate-sandbox '<payment-uuid-or-merchant-ref>' --create
```

Expected output includes payment UUID, merchant reference hash, normalized status, total display, and expiry. It must not include API keys, merchant IDs, signatures, QR URL query secrets, raw QR base64, or full provider payloads.

## 9. Simulate payment in KlikQRIS dashboard

Copy the transaction signature from the KlikQRIS dashboard and execute the simulation there. Do not paste the signature into terminal output, issue comments, commits, reports, or chat.

After the simulation, observe webhook processing and run:

```bash
docker compose -p telegram-bot-prod exec -T app php artisan klikqris:validate-sandbox '<payment-uuid-or-merchant-ref>' --reconcile
```

Expected result: authoritative status processing validates the exact `provider=klikqris` payment row and marks the order paid according to application lifecycle rules.

## 10. Required scenarios

Validate and record evidence for each scenario:

1. Initial sandbox checkout creates a payable QR.
2. Paid webhook processes successfully.
3. Duplicate webhook does not double-fulfill.
4. No-webhook reconciliation applies authoritative status.
5. Expired QR becomes unpayable after the provider expiry.
6. New checkout after expiry creates a fresh disposable checkout.
7. Merchant reference collision with a Pakasir row does not validate Pakasir. UUID lookup may reject a non-KlikQRIS UUID. Merchant reference lookup must select the exact `provider=klikqris` row.

## 11. Evidence table

| Scenario | Timestamp | Payment UUID | Merchant ref hash | Result | Notes |
| --- | --- | --- | --- | --- | --- |
| Initial checkout |  |  |  | PASS/FAIL |  |
| Paid webhook |  |  |  | PASS/FAIL |  |
| Duplicate webhook |  |  |  | PASS/FAIL |  |
| No-webhook reconciliation |  |  |  | PASS/FAIL |  |
| Expiry |  |  |  | PASS/FAIL |  |
| New checkout after expiry |  |  |  | PASS/FAIL |  |
| Pakasir collision safety |  |  |  | PASS/FAIL |  |

## 12. Production authorization

This runbook is for sandbox validation only. Passing every step does not authorize production credentials, production bot usage, production mode, production rollout, production deployment, or production payment enablement.
