## Agent skills

### Issue tracker

Issues and PRDs for this repo live as GitHub issues. See `docs/agents/issue-tracker.md`.

### Triage labels

Use the default triage labels: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, and `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

This is a single-context repo with `CONTEXT.md` and `docs/adr/`. See `docs/agents/domain.md` and `docs/agents/codebase.md` for the implementation map, flow ownership, invariants, and test entry points.
### Deployment platform

This repository does not use Railway. Production deploys run through GitHub Actions (`.github/workflows/deploy-production.yml`) from `main` and update the VPS over SSH. Do not introduce or invoke Railway commands, services, or configuration for this project.
### Configuration policy

`ENV` is reserved for secrets and credentials. Do not add new environment variables for catalog content or presentation settings such as product icons, labels, prices, stock, or other Admin-editable values. Store those values in the database and expose them through the Admin Dashboard so updates do not require an image rebuild or configuration-cache refresh. Existing legacy runtime/deployment knobs remain untouched unless a task explicitly migrates them.

For product icons specifically, use the optional Product `icon` field and the Admin Dashboard Tambah/Edit Produk form. Never reintroduce `TG_KEYBOARD_ICON_*`, `TG_ICON_*`, or other icon-related ENV settings.
