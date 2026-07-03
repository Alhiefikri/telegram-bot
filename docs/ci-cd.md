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

- `.github/workflows/admin-asset-smoke.yml`
  - Kept as a focused guard for the existing admin asset smoke contract.

- `.github/workflows/deploy-production.yml`
  - Triggers only by manual `workflow_dispatch` from `main`.
  - Runs release gate first: PHP tests, asset smoke, Docker build.
  - Deploys over SSH to the VPS with Docker Compose.
  - Verifies app health and scheduler heartbeat after deploy.

## Required GitHub secrets

Configure these in GitHub repository Settings → Secrets and variables → Actions:

- `PRODUCTION_HOST`: VPS host/IP.
- `PRODUCTION_SSH_PORT`: SSH port, for example `2468`. Optional; workflow defaults to `22` when empty.
- `PRODUCTION_USER`: SSH user on the VPS.
- `PRODUCTION_SSH_PRIVATE_KEY`: private deploy key allowed to SSH to the VPS.
- `PRODUCTION_PATH`: absolute path of the checked-out repo on the VPS, for example `/home/alhiefikri/github/telegram-bot`.

Production application secrets such as `APP_KEY`, `POSTGRES_PASSWORD`, `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, and payment credentials should stay in the VPS `.env`, not in the workflow file.

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
