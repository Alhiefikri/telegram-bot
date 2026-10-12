## Agent skills

### Issue tracker

Issues and PRDs for this repo live as GitHub issues. See `docs/agents/issue-tracker.md`.

### Triage labels

Use the default triage labels: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, and `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

This is a single-context repo with `CONTEXT.md` and `docs/adr/`. See `docs/agents/domain.md` and `docs/agents/codebase.md` for the implementation map, flow ownership, invariants, and test entry points.
### Deployment platform

This repository does not use Railway. Production deploys run through GitHub Actions (`.github/workflows/deploy-production.yml`) from `main` and update the VPS over SSH. Do not introduce or invoke Railway commands, services, or configuration for this project.
### CI/CD policy

Run and inspect CI/CD only when the admin explicitly asks for it. A request to commit and push means exactly that: stage, commit, push, then stop.

Never, on your own initiative: dispatch, re-run, cancel, poll, watch, or investigate GitHub Actions runs; push temporary branches or open draft PRs to obtain a CI run; wait for a run "to verify" a push. Pushing to `main` may start `.github/workflows/ci.yml` automatically — leave that run for the admin to inspect. A failing automatic run is not a blocker for the requested commit or push, and does not authorize follow-up work.

If a CI failure looks like a real blocker for the task at hand, report it in one line and stop; only continue when the admin asks you to run CI or fix it.
### Configuration policy

`ENV` is reserved for secrets and credentials. Do not add new environment variables for catalog content or presentation settings such as product icons, labels, prices, stock, or other Admin-editable values. Store those values in the database and expose them through the Admin Dashboard so updates do not require an image rebuild or configuration-cache refresh. Existing legacy runtime/deployment knobs remain untouched unless a task explicitly migrates them.

For product icons specifically, use the optional Product `icon` field and the Admin Dashboard Tambah/Edit Produk form. Never reintroduce `TG_KEYBOARD_ICON_*`, `TG_ICON_*`, or other icon-related ENV settings.

### Telegram button presentation

Default inline buttons are blue (`style: primary`), not Telegram's unstyled dark buttons. Catalog products/Plans with no available stock are red (`style: danger`); navigation buttons remain blue. Every catalog footer button has a relevant icon, using the existing DB-backed UI icon slots and their Unicode fallbacks. Keep this convention for future button changes.
