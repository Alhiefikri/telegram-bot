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
- Production deploys should go through the GitHub Actions workflow `Deploy production`, not ad hoc SSH commands, unless the user explicitly asks for an emergency/manual recovery. Expected flow: push to `main`, wait for CI/Admin asset smoke to pass, run `gh workflow run deploy-production.yml --ref main`, then watch/verify the run. The deploy workflow depends on GitHub secrets `PRODUCTION_HOST`, `PRODUCTION_USER`, `PRODUCTION_SSH_PORT`, `PRODUCTION_PATH`, and `PRODUCTION_SSH_PRIVATE_KEY`.
- Production Docker Compose must use the existing project name `telegram-bot-prod`. If touching deploy workflow or running remote compose commands, include `docker compose -p telegram-bot-prod --env-file .env ...`; using bare `docker compose --env-file .env ...` can create a duplicate `telegram-bot` stack and cause port conflicts.
