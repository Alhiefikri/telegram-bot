# CI/CD

## Branch strategy

Project ini memakai satu branch utama untuk integrasi dan sumber rilis:

- `main`: branch integrasi harian dan sumber deploy production. Semua pull request dan push ke `main` menjalankan CI.

Deploy production tidak otomatis dari push karena bot commerce ini punya database, queue, scheduler, Telegram webhook, dan payment flow. Release dilakukan manual dari `main` setelah CI hijau.

## Workflows

- `.github/workflows/ci.yml`
  - Validates every workflow with `actionlint` and audits locked Composer production dependencies plus npm dependencies.
  - Rejects duplicate migration filename prefixes via `./scripts/check-migration-prefixes.sh`, so migration order stays deterministic. Prefixes that already shipped with a duplicate are allowlisted in `scripts/migration-prefix-allowlist.txt` and are never renamed, because Laravel keys the `migrations` table by filename.
  - PHP 8.4 + Composer validate/install.
  - Enforces the Laravel Pint code style with `./vendor/bin/pint --test`.
  - Laravel test suite via `composer test`.
  - Keeps the fast SQLite suite parallel via ParaTest (`composer test -- --parallel`), then runs an authoritative PostgreSQL release lane against an isolated PostgreSQL 16 service with health checking and CI-only credentials, matching production's database major version.
  - Applies every migration to an empty PostgreSQL database, runs production-critical Telegram update, inventory, checkout, payment, and queue-state integration tests, then executes the process-level concurrency deploy gate with unexpected skips treated as failures.
  - Uploads PostgreSQL migration logs, JUnit XML, human-readable test/failure/skip summaries, and concurrency evidence on success or failure.
  - Node 24 + `npm ci`.
  - Vite/admin asset smoke via `../scripts/smoke-admin-assets.sh`.
  - Builds the production image once with BuildKit, reusing the GitHub Actions cache without changing the resulting content-addressed image.
  - Runs container-level verification against that image, then publishes successful `main` images to GHCR as `ghcr.io/<owner>/<repository>:<commit-sha>`.
  - Records the immutable `sha256` digest in a `production-image` commit status and the CI job summary.
  - Every job has a bounded timeout. Failed PHPUnit, Playwright, and Docker jobs upload diagnostic artifacts retained for 14 days.

- `.github/workflows/deploy-production.yml`
  - Triggers only by manual `workflow_dispatch` from `main`.
  - Pins the deployment to the dispatched revision (or an explicitly supplied full commit SHA), requires that SHA to be contained in `main`, and requires a successful `CI` workflow run for that exact SHA.
  - Does not rerun Composer, NPM, PHPUnit, asset smoke, or a Docker build; those are the required `CI` evidence for the pinned SHA.
  - Deploys over SSH to the VPS with Docker Compose project `telegram-bot-prod`.
  - Uses `./scripts/safe-compose-config.sh` before any Compose action so resolved production secrets are not printed.
  - Reads the image digest recorded on the deployment commit by the successful CI run and pulls that exact GHCR digest on the VPS. Production never rebuilds the application image.
  - Records the previous Git commit and immutable running image before changing the checkout, then runs `php artisan migrate --force` in a one-off candidate container before application cutover. Normal `app` and `scheduler` startup never owns migrations.
  - Aborts before cutover when migration fails, leaving the current containers available. If an internal gate or the public GitHub-runner smoke fails, restores the previous commit and immutable image automatically and verifies app and scheduler health.
  - Writes `release-result.txt` with `deploy-succeeded`, `migration-failed-no-cutover`, or a reason-specific `*-rollback-succeeded` / `*-rollback-failed` result, so operators can distinguish a contained deploy failure from a failed recovery.
  - Verifies that the running `app` and `scheduler` containers use the approved image ID and prints the commit tag and immutable digest in secret-free release logs.
  - Verifies app health, scheduler heartbeat, candidate-release failed jobs, traffic-tolerant queue health, Telegram webhook external registration, a public external smoke check, and absence of duplicate running Telegram Bot Compose projects after deploy.
  - Shares the `production-mutation` GitHub Actions concurrency group and a non-blocking VPS `flock` lock with failed-job recovery. A concurrent mutation fails safely rather than racing the release.
  - Uses SSH batch mode, a connection timeout, and server keepalives to make long VPS operations resilient to idle disconnects.
  - Captures a bounded, redacted Compose log, Compose status, and secret-free JSON health summary as workflow artifacts, including when a release gate fails. Payload/body lines are dropped, while credentials in key/value, JSON, bearer-token, Telegram-token, and URL-query forms are redacted.

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

## Dependency vulnerability policy

CI blocks a release when either dependency audit fails:

- Composer production dependencies use `composer audit --locked --no-dev --abandoned=fail`. Any published advisory or abandoned production package is blocking, regardless of severity.
- npm uses `npm audit --audit-level=high`. High and critical findings are blocking. Moderate and low findings must be reviewed during the next dependency update, but do not by themselves block a release.

Do not suppress an advisory only to make CI green. A temporary exception must be documented in a GitHub issue with the affected package, advisory identifier, exposure analysis, compensating control, owner, and expiry date.

Dependabot checks GitHub Actions, Composer, and npm weekly through `.github/dependabot.yml`. Workflow actions must remain pinned to a reviewed full commit SHA. The trailing version comment is informational and is the version Dependabot updates.

## Required GitHub governance settings

Some controls live in GitHub and cannot be enforced by committed files. Repository administrators must configure and periodically verify the following.

### Actions

- Default workflow token permission: read-only.
- Pull request approval permission for workflows: disabled.
- Allowed Actions: GitHub-owned actions, verified creators, plus the reviewed `shivammathur/setup-php` and `docker/*` patterns.
- Require actions to be pinned to a full-length commit SHA.

Verify through the API:

```bash
gh api repos/{owner}/{repo}/actions/permissions
gh api repos/{owner}/{repo}/actions/permissions/workflow
gh api repos/{owner}/{repo}/actions/permissions/selected-actions
```

Expected values include `allowed_actions: selected`, `sha_pinning_required: true`, `default_workflow_permissions: read`, and `can_approve_pull_request_reviews: false`.

### `main` branch

Protect `main` with these settings:

- Require a pull request before merging, with at least one approval.
- Require the current successful CI check names, including `Workflow validation and dependency audit`, `PHP tests`, `PostgreSQL migration and concurrency release gate`, `Frontend build and asset smoke`, `Customer browser smoke`, and `Build, verify, and publish production image`.
- Require branches to be up to date before merging.
- Require all review conversations to be resolved.
- Do not allow force pushes or branch deletion.
- Include administrators so the release path cannot be bypassed accidentally.

Verify through the API:

```bash
gh api repos/{owner}/{repo}/branches/main/protection
```

GitHub Free does not provide branch protection for private repositories. If the API returns HTTP 403 with `Upgrade to GitHub Pro or make this repository public`, upgrade the repository plan or make the repository public before treating this control as satisfied.

### `production` environment

The `production` environment must require at least one explicit reviewer before the SSH deploy job starts. Restrict deployment branches to `main`, disable administrator bypass where the plan supports it, and keep both production mutation workflows targeted at `environment: production`.

Verify through the API:

```bash
gh api repos/{owner}/{repo}/environments/production
gh api repos/{owner}/{repo}/environments/production/deployment-branch-policies
```

Expected output contains a `required_reviewers` protection rule and a `main` deployment branch policy. Required-reviewer availability for private repositories depends on the GitHub plan and currently may require GitHub Enterprise. If configuration returns HTTP 422 mentioning the billing plan, move the repository to a plan that supports the rule or make it public before treating production approval as protected. Verify support through the API rather than assuming a GitHub Pro or Team upgrade is sufficient.

## Production environment protection

The workflows already target a GitHub Environment named `production`. Its required reviewer and `main`-only deployment branch policy are mandatory governance controls, subject to the GitHub plan limitation documented above.

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

### Binance Pay Order ID activation

The first release is compatibility-only: it applies the additive schema and keeps normal buyer checkout traffic on the configured legacy mode. The Telegram account identified by `TELEGRAM_ADMIN_USER_ID` is the only pre-activation canary: its Binance checkout uses the exact catalog amount and displays **I've Paid / Saya Sudah Bayar** plus the Order ID prompt. API credentials and a Pay ID/UID must be configured or even the admin remains on the legacy path.

From that admin account, create a fresh Binance checkout, pay the exact displayed amount, tap **I've Paid / Saya Sudah Bayar**, copy the transfer Order ID from Binance Pay Order Details, and submit it to the bot. Confirm that automatic verification completes the order and delivers stock. The activation command searches the database for this successful admin payment within `BINANCE_ORDER_ID_CANARY_MAX_AGE_HOURS` (24 hours by default); a flag without that evidence is rejected.

After that canary passes and the external smoke gate is green, activate Order ID mode in the same protected production workflow:

```bash
gh workflow run deploy-production.yml --ref main -f mark_binance_order_id_ready=true
gh run watch
```

The workflow runs `php artisan binance:mark-order-id-ready --confirm-canary` only after the candidate is healthy. The command is idempotent and refuses to contract the legacy amount index when pending legacy amounts would collide. If activation fails, the release remains on the compatibility mode; resolve the pending legacy payments and rerun the guarded workflow. Never run the command through an ad-hoc SSH session or switch the `.env` mode alone.

## Migration compatibility and approval policy

The SQLite `PHP tests` job is the fast general regression lane. The `PostgreSQL migration and concurrency release gate` job is authoritative for production database compatibility because it uses the same database family as production, starts from an empty database, and exercises locking and process-level concurrency behavior. A deployment commit is not eligible for release unless the complete `CI` workflow, including this PostgreSQL job, succeeds for that exact SHA.

Every production migration must follow expand-and-contract compatibility because the old application remains live while the candidate migration runs and may be restored after cutover:

1. **Expand** with additive, backward-compatible schema changes such as nullable columns, new tables, or new indexes created without blocking production traffic.
2. Deploy code that can operate with both the old and expanded schema. Backfill data in a separate bounded operation when necessary.
3. **Contract** only in a later release after the old code is no longer a rollback target.

Do not include destructive renames, column/table drops, incompatible type changes, irreversible data rewrites, or long blocking DDL in the automatic release migration. Those changes require a verified database backup and explicit human approval before dispatching the workflow. If a change cannot preserve compatibility with the retained image, treat it as a planned maintenance release with a documented restore procedure rather than relying on automatic application rollback.

The disposable harness `./scripts/test-deploy-production-release.sh` exercises successful deploy, migration failure before cutover, application health failure with successful rollback, rollback health failure, and external-smoke rollback. `./scripts/test-redact-release-diagnostics.sh` verifies that representative payload and credential forms cannot enter the diagnostic artifact. `./scripts/test-check-migration-prefixes.sh` verifies that the migration prefix guard fails on a new duplicate, tolerates an allowlisted historical duplicate, and rejects a stale allowlist entry. CI runs these contracts through the PHP hardening suite and release harness.

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

- `php artisan ops:health --db --queue-schema --queue-depth --supervisor`
- `php artisan ops:health --scheduler-heartbeat=180` in the scheduler container
- `php artisan ops:check-failed-jobs --snapshot` before release mutation, followed by `php artisan ops:check-failed-jobs --max=0 --baseline-stdin` after cutover
- `php artisan ops:check-queue-depth --max=50 --max-oldest-age=120`
- `php artisan ops:check-telegram-webhook --max-pending=25 --observation-seconds=30 --sample-interval=5`
- An external GitHub runner runs `scripts/smoke-production.sh` against `PRODUCTION_SMOKE_BASE_URL`, which checks `/up` and the representative `/admin` route without SSHing into the VPS.
- Running Docker containers with Compose label `com.docker.compose.project` must not include another `telegram-bot*` project besides `telegram-bot-prod`.

`PRODUCTION_SMOKE_BASE_URL` is a required non-secret GitHub Environment variable, for example `https://store.example.com`. It must be the public HTTPS base URL, not a private hostname or a webhook URL.

### Traffic-tolerant release thresholds

- **Failed jobs:** zero records above the secret-free pre-release `failed_jobs.id` watermark. Retained historical records remain excluded even when their timestamps overlap the release, while every newly inserted failed record blocks the candidate. The scalar watermark keeps the query and artifact bounded. Inspect `health-summary.json` and the redacted logs, then use targeted recovery only when applicable.
- **Queue:** at most 50 queued jobs and the oldest queued job must be no more than 120 seconds old. This permits normal bursts while detecting workers that have stopped draining. If it blocks, inspect worker/scheduler health and the artifact; do not flush the queue to force a release through.
- **Telegram:** each registration property must be healthy, and the 30-second observation must see at least one sample at or below 25 pending updates. This avoids failing on a transient update while persistent backlog remains visible. If it blocks, inspect Telegram's status and deployment artifacts before changing the webhook.
- **External smoke:** `/up` and `/admin` must be reachable from GitHub Actions after container health succeeds. Failure immediately invokes the same locked rollback operation, verifies the restored app and scheduler health, records an `external-smoke-failed-rollback-*` result, and still fails the workflow so the candidate cannot be reported as deployed.

The artifact bundle is deliberately bounded and secret-safe: it includes `compose-ps.json`, `health-summary.json`, and a redacted 120-line Compose log. Do not add resolved Compose configuration or `.env` files to workflow artifacts.

## Emergency manual recovery

Use this only when automatic rollback reports `deploy-failed-rollback-failed`. Read `previous-commit.txt` and `previous-image.txt` from the deployment artifact, then run on the VPS from `PRODUCTION_PATH`:

```bash
PREVIOUS_COMMIT='<40-character commit from previous-commit.txt>'
PREVIOUS_IMAGE='<immutable ghcr.io/...@sha256:... from previous-image.txt>'

git fetch --prune origin main
git checkout --detach "$PREVIOUS_COMMIT"
git reset --hard "$PREVIOUS_COMMIT"
docker pull "$PREVIOUS_IMAGE"
APP_IMAGE="$PREVIOUS_IMAGE" docker compose -p telegram-bot-prod --env-file .env up -d --no-build --remove-orphans
docker compose -p telegram-bot-prod --env-file .env exec -T app php artisan ops:health --db --queue-schema --queue-depth --supervisor
docker compose -p telegram-bot-prod --env-file .env exec -T scheduler php artisan ops:health --scheduler-heartbeat=180
docker compose -p telegram-bot-prod --env-file .env ps
```

Do not run a down migration during application rollback. If the candidate migration was not expand-and-contract compatible, stop and follow the pre-approved database restore procedure instead of guessing at schema reversal.

## Safe failed-job recovery

Use recovery only for failed Telegram update processing after checking the production incident. It does not recover arbitrary queue jobs.

```bash
gh workflow run recover-failed-jobs.yml --ref main -f confirm=RECOVER_TELEGRAM_UPDATES
gh run watch
```

The workflow prints only aggregate classification counts before mutation, so it does not expose job payloads or production secrets. It refuses to run alongside a production deploy at both GitHub Actions and VPS levels. If verification does not reach zero failed jobs and zero queue depth, treat the remaining jobs as unsupported or otherwise unsafe to replay, inspect them through the appropriate domain procedure, and do not use `queue:retry all`.
