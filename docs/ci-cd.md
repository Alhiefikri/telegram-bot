# CI/CD

## Branch strategy

Project ini memakai satu branch utama untuk integrasi dan sumber rilis:

- `main`: branch integrasi harian dan sumber deploy production. Semua pull request dan push ke `main` menjalankan CI.

Deploy production tidak otomatis dari push karena bot commerce ini punya database, queue, scheduler, Telegram webhook, dan payment flow. Release dilakukan manual dari `main` setelah CI hijau.

## Workflows

- `.github/workflows/ci.yml`
  - PHP 8.4 + Composer validate/install.
  - Laravel test suite via `composer test`.
  - Node 24 + `npm ci`.
  - Vite/admin asset smoke via `../scripts/smoke-admin-assets.sh`.
  - Production Docker image build check.

- `.github/workflows/deploy-production.yml`
  - Triggers only by manual `workflow_dispatch` from `main`.
  - Pins the deployment to the dispatched revision (or an explicitly supplied full commit SHA), requires that SHA to be contained in `main`, and requires a successful `CI` workflow run for that exact SHA.
  - Does not rerun Composer, NPM, PHPUnit, asset smoke, or the CI-parity Docker build; those are the required `CI` evidence for the pinned SHA.
  - Deploys over SSH to the VPS with Docker Compose project `telegram-bot-prod`.
  - Uses `./scripts/safe-compose-config.sh` before any Compose action so resolved production secrets are not printed.
  - Builds the production image on the VPS after checking out the pinned SHA, then starts that already-built image with `docker compose ... up -d --no-build --remove-orphans`. This preserves the VPS build as the release artifact because production secrets and the deploy host stay local to the VPS.
  - Verifies app health, scheduler heartbeat, failed jobs, queue depth, Telegram webhook external registration, and absence of duplicate running Telegram Bot Compose projects after deploy.

## Required GitHub secrets

Configure these in GitHub repository Settings → Secrets and variables → Actions:

- `PRODUCTION_HOST`: VPS host/IP.
- `PRODUCTION_SSH_PORT`: SSH port, for example `2468`. Optional; workflow defaults to `22` when empty.
- `PRODUCTION_USER`: SSH user on the VPS.
- `PRODUCTION_SSH_PRIVATE_KEY`: private deploy key allowed to SSH to the VPS.
- `PRODUCTION_PATH`: absolute path of the checked-out repo on the VPS, for example `/home/alhiefikri/github/telegram-bot`.

Production application secrets such as `APP_KEY`, `POSTGRES_PASSWORD`, `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, and payment credentials must stay in the VPS `.env`, not in the workflow file. The deploy workflow validates Compose with `./scripts/safe-compose-config.sh`, which uses `docker compose config --quiet`; do not run or paste plain `docker compose ... config` output for production-like environments because resolved Compose output includes secret values.

## Recommended GitHub environment protection

Create a GitHub Environment named `production` and add a required reviewer if you want manual approval before SSH deploy. The workflow already targets `environment: production`.

## Release command

After CI on `main` is green, deploy production manually from local with:

```bash
git checkout main
git pull --ff-only origin main
gh workflow run deploy-production.yml --ref main
```

Watch the run with:

```bash
gh run watch
```

The VPS deploy step expects the remote repository at `PRODUCTION_PATH` to be able to `git fetch origin main` and run `docker compose` from the repo root.

The post-deploy release interface is intentionally cheap but deeper than liveness:

- `php artisan ops:health --db --queue-schema --supervisor`
- `php artisan ops:health --scheduler-heartbeat=180` in the scheduler container
- `php artisan ops:check-failed-jobs --max=0`
- `php artisan ops:check-queue-depth --max=0`
- `php artisan ops:check-telegram-webhook --max-pending=0`
- Running Docker containers with Compose label `com.docker.compose.project` must not include another `telegram-bot*` project besides `telegram-bot-prod`.

The Telegram webhook check verifies only booleans/counts: Telegram API success, HTTPS target, webhook secret configured, webhook URL configured, webhook URL matching `APP_URL/api/webhook/telegram`, `pending_update_count`, and whether Telegram reports a last error. It must not print the bot token, webhook secret, or full private webhook URL.
