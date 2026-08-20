## Agent skills

### Issue tracker

Issues and PRDs for this repo live as GitHub issues. See `docs/agents/issue-tracker.md`.

### Triage labels

Use the default triage labels: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, and `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

This is a single-context repo with `CONTEXT.md` and `docs/adr/`. See `docs/agents/domain.md` and `docs/agents/codebase.md` for the implementation map, flow ownership, invariants, and test entry points.
### Deployment platform

This repository does not use Railway. Production deploys run through GitHub Actions (`.github/workflows/deploy-production.yml`) from `main` and update the VPS over SSH. Do not introduce or invoke Railway commands, services, or configuration for this project.
