---
name: telegram-bot-cicd
description: "Operate this repo's GitHub Actions CI/CD safely: use GitHub-hosted CI, dispatch production deploys from green main commits through the VPS runner, and verify production release identity."
---

# Telegram Bot CI/CD

Use this skill when the user asks to check, run, repair, or explain the
`telegram-bot` CI/CD path, self-hosted GitHub Actions runners, production deploy
workflow, release alignment, or production release identity.

This repository does not use Railway. Do not introduce Railway commands,
services, or configuration.

## Fixed Topology

- Repository: `Alhiefikri/telegram-bot`
- Main branch: `main`
- CI workflow: `.github/workflows/ci.yml`
- Deploy workflow: `.github/workflows/deploy-production.yml`
- Recovery workflow: `.github/workflows/recover-failed-jobs.yml`
- Production Compose project: `telegram-bot-prod`
- Production public release marker: `https://lhiestore.web.id/release-sha.txt`
- Production canonical URL: `https://lhiestore.web.id`
- Production SSH host for read-only checks: `ssh -p 2468 alhiefikri@43.134.177.28`
- Runner VPS: `ssh ubuntu@43.156.207.168`
- Runner install path on the runner VPS: `/opt/actions-runner-telegram-bot`
- Runner service on the runner VPS:
  `actions.runner.Alhiefikri-telegram-bot.telegram-bot-ci-vps.service`
- Intended active runner name: `telegram-bot-ci-vps`
- Intended active runner labels: `self-hosted`, `Linux`, `X64`, `deploy`

The runner VPS is only a GitHub Actions runner. Do not deploy the application
there and do not inspect or mutate unrelated workloads. In particular, do not
touch `viet-tool` or any unrelated container/process unless the user explicitly
asks for that separate system.

Production is a different VPS reached by the deploy workflow over SSH.

## Runner Rules

CI jobs route to:

```text
ubuntu-24.04
```

Scheduled supplier regression jobs also use `ubuntu-24.04`. Standard hosted
runner compute is free while the repository is public; artifact and registry
storage remain separately limited.

Production deploy and recovery jobs route to:

```text
[self-hosted, Linux, X64, deploy]
```

Before dispatching or rerunning production deploys, confirm the deploy label
points to the intended runner:

```bash
gh api repos/Alhiefikri/telegram-bot/actions/runners \
  --jq '.runners[] | {id,name,status,busy,labels:[.labels[].name]}'
```

Expected healthy state:

- `telegram-bot-ci-vps` is `online`.
- `telegram-bot-ci-vps` has the `deploy` label.
- Any old local runner, especially `alhiefikri-telegram-bot`, must not have the
  `deploy` label.

If a job is stuck on a stale runner, identify the attached runner before
retrying:

```bash
gh api repos/Alhiefikri/telegram-bot/actions/runs/<run-id>/jobs \
  --jq '.jobs[] | {name,status,conclusion,runner_name,runner_id,labels:.labels,started_at,completed_at}'
```

Do not repeatedly rerun a workflow that is attached to the wrong runner. Fix the
runner label/state first, cancel the stuck run once, then dispatch a fresh run.

The runner VPS needs the production host key in `~ubuntu/.ssh/known_hosts`.
Do not use `StrictHostKeyChecking=no`. If deploy fails with
`Host key verification failed`, copy or verify the trusted production host key
before retrying.

## Normal Release Flow

Start from local repo root:

```bash
git status --short --branch
git rev-parse HEAD
git ls-remote origin refs/heads/main
```

Do not overwrite unrelated local changes. This repo often has user-owned
untracked smoke images or drafts; leave them alone unless they are part of the
current request.

Confirm CI for the exact deployment SHA:

```bash
gh run list --workflow ci.yml --branch main --limit 10 \
  --json databaseId,headSha,status,conclusion,createdAt,url
```

The deploy workflow itself enforces that the target SHA is contained in `main`
and has a successful push CI run. Do not bypass that gate with manual server
commands.

Dispatch deploy from `main` with a full SHA when the user wants the current
commit deployed:

```bash
gh workflow run deploy-production.yml --ref main \
  -f commit=<full-40-char-main-sha> \
  -f recover_failed_telegram_updates=false \
  -f apply_telegram_outbound_contract=false \
  -f bootstrap_local_rollback_image=false \
  -f initialize_credential_fingerprint_key=false
```

Use `bootstrap_local_rollback_image=true` only for the one-time bridge from a
legacy production container that still uses a local/mutable image instead of a
GHCR digest. After a successful digest-based deploy, leave it false.

Use `expected_app_url=https://lhiestore.web.id` only when the GitHub production
environment variable is missing or temporarily wrong. Prefer fixing the
environment variable instead of carrying overrides:

```bash
gh variable set PRODUCTION_APP_URL --env production --body https://lhiestore.web.id
gh variable set PRODUCTION_SMOKE_BASE_URL --env production --body https://lhiestore.web.id
```

Do not set `apply_telegram_outbound_contract=true` as part of an ordinary fast
deploy. That input runs the destructive deferred Telegram outbound contract and
requires explicit operator intent plus the workflow's verified backup gate.

## Monitoring

Poll boundedly. Avoid blind loops.

```bash
gh run view <run-id> --json status,conclusion,jobs,url
```

For failure details:

```bash
gh run view <run-id> --log-failed
```

Key successful deploy steps:

- `Release gate`
- `Require successful CI for deployment commit`
- `Resolve the CI-approved immutable image`
- `Authenticate VPS Docker to GHCR for this release`
- `Deploy over SSH`
- `Reconcile remote release state`
- `Run external public smoke checks`
- `Remove transient GHCR credentials from VPS`

If `Deploy over SSH` starts, treat production as possibly mutated. Do not cancel
casually after that point; inspect release state and let reconciliation finish
when possible.

## Production Verification

After a successful deploy, prove alignment with independent evidence:

```bash
curl -fsS --max-time 15 https://lhiestore.web.id/release-sha.txt
```

The output must equal the intended full commit SHA.

For read-only server verification, use the `audit-production-ssh` skill first
and keep commands bounded and secret-safe. Useful checks from the production
repo root:

```bash
ssh -p 2468 alhiefikri@43.134.177.28 \
  'cd "$HOME/github/telegram-bot" && git rev-parse HEAD'

ssh -p 2468 alhiefikri@43.134.177.28 \
  'cd "$HOME/github/telegram-bot" && latest="$(find .release-gate-artifacts -maxdepth 1 -type d -name "<run-id>-*" | sort | tail -1)"; printf "%s\n" "$latest"; [ -n "$latest" ] && { cat "$latest/release-result.txt"; cat "$latest/release-state.env"; }'

ssh -p 2468 alhiefikri@43.134.177.28 \
  'docker ps --format "table {{.Names}}\t{{.Image}}\t{{.Status}}" | sed -n "1,8p"'
```

Expected successful compatible release state:

```text
deploy-succeeded-compatible-contract-pending
state=compatible-candidate-running
deploy_sha=<full-sha>
app_image=ghcr.io/alhiefikri/telegram-bot@sha256:<digest>
```

`deploy-succeeded-compatible-contract-pending` is a successful fast deploy. It
means the compatible app is live and the destructive Telegram outbound contract
was intentionally not applied.

## Failure Handling

Use the workflow diagnostics first. Do not manually `git pull`, run migrations,
restart containers, or run `docker compose up` on production unless the user has
explicitly authorized manual production mutation.

Common failures:

- `Host key verification failed`: the runner VPS lacks the production host key.
  Fix `~ubuntu/.ssh/known_hosts` on the runner VPS using a trusted key; do not
  disable host-key checking.
- Job stuck on `Set up job` or attached to `alhiefikri-telegram-bot`: remove the
  `deploy` label from stale runners, make sure `telegram-bot-ci-vps` is online
  with `deploy`, cancel the stuck run once, then dispatch a fresh run.
- `Remote deployment state could not prove a healthy candidate release`: inspect
  the release artifacts and production release state before retrying. If
  production inspection is needed, use `audit-production-ssh`.
- External smoke fails: let the workflow rollback and rollback-smoke steps run.
  A failed workflow can still mean rollback succeeded; read `release-result.txt`.

Stop and ask for direction when the next action would be a manual production
mutation, destructive contract migration, secret inspection, or intervention in
an unrelated system.
