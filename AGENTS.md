## Agent skills

### Issue tracker

Issues and PRDs for this repo live as GitHub issues. External PRs are not treated as a request surface. See `docs/agents/issue-tracker.md`.

### Triage labels

Using standard default triage labels (needs-triage, needs-info, ready-for-agent, ready-for-human, wontfix). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context repo layout with one CONTEXT.md and docs/adr/ at the root. See `docs/agents/domain.md`.

## Local operations reminders

- After code, config, env, Dockerfile, Composer, npm, route, queue, scheduler, or webhook-related changes that should affect the running bot/website, remind the user that Docker must be refreshed. When asked to apply it locally, use the active project name instead of creating a duplicate stack: `docker compose -p telegram-bot-prod up -d --build --remove-orphans`, then verify with `docker compose -p telegram-bot-prod ps` and the relevant `php artisan ops:health` check.
- Before answering or deploying anything about production, verify the actual deploy source from git/workflows/docs. Current intended release flow in `docs/ci-cd.md` and `.github/workflows/deploy-production.yml` is manual deploy from `main` with `gh workflow run deploy-production.yml --ref main`; do not introduce or assume a separate `production` branch unless the workflow/docs change.
