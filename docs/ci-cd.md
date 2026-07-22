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
  - Builds the production image once with BuildKit, reusing the GitHub Actions cache without changing the resulting content-addressed image.
  - Runs container-level verification against that image, then publishes successful `main` images to GHCR as `ghcr.io/<owner>/<repository>:<commit-sha>`.
  - Records the immutable `sha256` digest in a `production-image` commit status and the CI job summary.

- `.github/workflows/deploy-production.yml`
  - Triggers only by manual `workflow_dispatch` from `main`.
  - Pins the deployment to the dispatched revision (or an explicitly supplied full commit SHA), requires that SHA to be contained in `main`, and requires a successful `CI` workflow run for that exact SHA.
  - Does not rerun Composer, NPM, PHPUnit, asset smoke, or a Docker build; those are the required `CI` evidence for the pinned SHA.
  - Deploys over SSH to the VPS with Docker Compose project `telegram-bot-prod`.
  - Uses `./scripts/safe-compose-config.sh` before any Compose action so resolved production secrets are not printed.
  - Reads the image digest recorded on the deployment commit by the successful CI run, pulls that exact GHCR digest on the VPS, and starts it with `docker compose ... up -d --no-build --remove-orphans`. Production never rebuilds the application image.
  - Verifies that the running `app` and `scheduler` containers use the approved image ID and prints the commit tag and immutable digest in secret-free release logs.
  - Verifies app health, scheduler heartbeat, candidate-release failed jobs, traffic-tolerant queue health, Telegram webhook external registration, a public external smoke check, and absence of duplicate running Telegram Bot Compose projects after deploy.
  - Shares the `production-mutation` GitHub Actions concurrency group and a non-blocking VPS `flock` lock with failed-job recovery. A concurrent mutation fails safely rather than racing the release.
  - Uses SSH batch mode, a connection timeout, and server keepalives to make long VPS operations resilient to idle disconnects.
  - Captures a bounded, redacted Compose log, Compose status, and secret-free JSON health summary as workflow artifacts, including when a release gate fails.

- `.github/workflows/recover-failed-jobs.yml`
  - Runs manually in the `production` environment only after the operator types `RECOVER_TELEGRAM_UPDATES`.
  - Prints a count-only dry-run summary first, then directly handles only stored `ProcessTelegramUpdate` failures. It classifies retryable updates, stale deleted updates, and already terminal updates. Unsupported failed job types are never replayed or deleted.
  - A direct retry that still fails is marked terminal only with `--prune-unrecoverable`; stale and already terminal Telegram records are removed from `failed_jobs` without replaying them.
  - The workflow then requires both failed-job count and queue depth to return to zero. If an unsupported failed job remains, recovery fails visibly for manual, domain-specific handling rather than replaying it.

## Required GitHub secrets

Configure these in GitHub repository Settings → Secrets and variables → Actions:

- `PRODUCTION_HOST`: VPS host/IP.
- `PRODUCTION_SSH_PORT`: SSH port, for example `2468`. Optional; workflow defaults to `22` when empty.
- `PRODUCTION_USER`: SSH user on the VPS.
- `PRODUCTION_SSH_PRIVATE_KEY`: private deploy key allowed to SSH to the VPS.
- `PRODUCTION_PATH`: absolute path of the checked-out repo on the VPS, for example `/home/alhiefikri/github/telegram-bot`.

Production application secrets such as `APP_KEY`, `POSTGRES_PASSWORD`, `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, and payment credentials must stay in the VPS `.env`, not in the workflow file. The deploy workflow validates Compose with `./scripts/safe-compose-config.sh`, which uses `docker compose config --quiet`; do not run or paste plain `docker compose ... config` output for production-like environments because resolved Compose output includes secret values.

CI publishes with the workflow-scoped `GITHUB_TOKEN`; only the image job receives `packages: write` and `statuses: write`, while all other workflow jobs remain read-only. If the GHCR package is private, authenticate Docker on the VPS once using a dedicated machine user or fine-grained token with read-only package access. Do not use a personal token with repository write access:

```bash
printf '%s' "$GHCR_READ_TOKEN" | docker login ghcr.io --username "$GHCR_USERNAME" --password-stdin
```

The credential remains in the VPS Docker credential store and is not passed through deployment logs or workflow artifacts.

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

## Image identity and retention

Each successful push to `main` publishes one commit-addressed GHCR tag. Treat tags as lookup aids only; the deployment consumes the immutable `ghcr.io/<owner>/<repository>@sha256:<digest>` reference recorded by CI in the commit's `production-image` status. Commit statuses remain attached to repository history and do not consume GitHub Actions artifact storage.

Keep every GHCR digest that is currently deployed or is a supported rollback target. A safe baseline is to retain at least the latest 30 successful `main` images and never delete a digest reported by production. Configure package cleanup accordingly in GitHub Packages; deleting a commit tag is safe only after confirming its digest is neither running nor retained for rollback.

Identify the release selected by the latest deployment in its `Approved production image` job summary. On the VPS, identify the exact running image without printing environment variables or credentials:

```bash
docker compose -p telegram-bot-prod --env-file .env ps -q app \
  | xargs docker inspect --format '{{.Config.Image}} {{.Image}}'
docker compose -p telegram-bot-prod --env-file .env ps -q scheduler \
  | xargs docker inspect --format '{{.Config.Image}} {{.Image}}'
```

The first value is the configured immutable GHCR reference and the second is the local content-addressed image ID. They should correspond to the digest printed in the deployment summary.

The post-deploy release interface is intentionally cheap but deeper than liveness:

- `php artisan ops:health --db --queue-schema --supervisor`
- `php artisan ops:health --scheduler-heartbeat=180` in the scheduler container
- `php artisan ops:check-failed-jobs --max=0 --since=<release-start>`
- `php artisan ops:check-queue-depth --max=50 --max-oldest-age=120`
- `php artisan ops:check-telegram-webhook --max-pending=25 --observation-seconds=30 --sample-interval=5`
- An external GitHub runner runs `scripts/smoke-production.sh` against `PRODUCTION_SMOKE_BASE_URL`, which checks `/up` and the representative `/admin` route without SSHing into the VPS.
- Running Docker containers with Compose label `com.docker.compose.project` must not include another `telegram-bot*` project besides `telegram-bot-prod`.

`PRODUCTION_SMOKE_BASE_URL` is a required non-secret GitHub Environment variable, for example `https://store.example.com`. It must be the public HTTPS base URL, not a private hostname or a webhook URL.

### Traffic-tolerant release thresholds

- **Failed jobs:** zero failures whose `failed_at` is at or after the release start time. Retained historical records and failures already quarantined before deployment do not block the candidate. A new failure blocks the release: inspect the `health-summary.json` artifact and the redacted logs, then use the targeted recovery procedure only when applicable.
- **Queue:** at most 50 queued jobs and the oldest queued job must be no more than 120 seconds old. This permits normal bursts while detecting workers that have stopped draining. If it blocks, inspect worker/scheduler health and the artifact; do not flush the queue to force a release through.
- **Telegram:** each registration property must be healthy, and the 30-second observation must see at least one sample at or below 25 pending updates. This avoids failing on a transient update while persistent backlog remains visible. If it blocks, inspect Telegram's status and deployment artifacts before changing the webhook.
- **External smoke:** `/up` and `/admin` must be reachable from GitHub Actions after container health succeeds. If it blocks, treat this as public routing, TLS, DNS, proxy, or web-container incident even if in-VPS checks passed.

The artifact bundle is deliberately bounded and secret-safe: it includes `compose-ps.json`, `health-summary.json`, and a redacted 120-line Compose log. Do not add resolved Compose configuration or `.env` files to workflow artifacts.

## Safe failed-job recovery

Use recovery only for failed Telegram update processing after checking the production incident. It does not recover arbitrary queue jobs.

```bash
gh workflow run recover-failed-jobs.yml --ref main -f confirm=RECOVER_TELEGRAM_UPDATES
gh run watch
```

The workflow prints only aggregate classification counts before mutation, so it does not expose job payloads or production secrets. It refuses to run alongside a production deploy at both GitHub Actions and VPS levels. If verification does not reach zero failed jobs and zero queue depth, treat the remaining jobs as unsupported or otherwise unsafe to replay, inspect them through the appropriate domain procedure, and do not use `queue:retry all`.
