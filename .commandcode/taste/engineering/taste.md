# Taste

## Domain and data modelling
- Represents money/state domains as an append-only immutable ledger plus an atomically maintained projection, with a per-source idempotency key and an immutable economic source identity, so replays return the existing result instead of a second effect. Confidence: 0.75
- Treats one service as the sole seam allowed to mutate such state; models elsewhere must not write the balance directly (projection column deliberately not mass assignable). Confidence: 0.7
- Enforces invariants in the database as the final guard — PostgreSQL check constraints (non-negative balance/amount, positive amount) and unique indexes over idempotency/source claims — not only in application code. Confidence: 0.7
- Prefers fail-safe behaviour over fabricated data: when a projection cannot be reconciled with its source of truth, render an explicit localized "unavailable" state rather than a number. Confidence: 0.65
- Keeps automated reconciliation/checking read-only and advisory (a `store-balance:check`-style command that reports mismatches and exits non-zero); never silently repairs state — a repair must be its own explicit, idempotent, audited operation. Confidence: 0.65

## Code and testing conventions (Laravel/PHP)
- Writes `final` classes with constructor property promotion, explicit return types, enums that carry their own behaviour (`direction()`, `isCredit()`), and model guards that throw on update/delete of immutable rows. Confidence: 0.6
- Tests new concurrency-sensitive behaviour with process-level races (pcntl fork, ready/start markers, result files) behind a dedicated group whose harness refuses to run unless it connects to an isolated test database (`DB_CONNECTION=pgsql`, database name must match test, not production). Confidence: 0.65
- Covers database constraints in tests too: assert that raw inserts violate the expected named constraint. Confidence: 0.55
- Wants fixes to follow established best practices and be grounded in official library documentation — explicitly asks the agent to "refer context7" (the context7 MCP) for framework/API behaviour instead of ad-hoc patching. Confidence: 0.6
- Expects a fixed defect to be locked down by exhaustive regression coverage of every condition, not just the reproduced case: a data-provider matrix over all relevant provider/system response variants (key missing/nulled/aliased, zero, contradictory aliases, negative/fractional values, HTTP failures, expired/success states, pre-fix stuck rows) plus end-to-end assertions on the money path, justified as preventing recurrence in production. Confidence: 0.75

## Production debugging / auditing
- Diagnoses production-only incidents against live evidence, but strictly read-only: he explicitly asks for a read-only production audit over SSH (host identity/time, deployed commit and working-tree state, memory/disk capacity, running containers, failed units, bounded log windows) and expects nothing on the production host or its data to be changed. Confidence: 0.55

## Deployment / CI-CD workflow
- Treats a deploy as finished only when verified against independent production evidence, not the CI conclusion alone: the live site's release SHA marker must equal the CI-green commit, the site must answer HTTP 200, and the running containers must use the image digest approved during CI. Confidence: 0.6
- Deployment gates are repo-scripted and testable — release/redaction/concurrency guard shell scripts each have their own test script that is run locally before pushing. Confidence: 0.5
- Never renames an already-applied migration; a duplicate timestamp prefix is resolved by renaming the later, not-yet-shipped migration (or allowlisting only prefixes already live), after confirming via local DB, migration history, and the current production SHA that it has never been applied anywhere. Confidence: 0.65

## Local dogfood environment
- Verifies UI/behaviour changes in a live local environment instead of trusting the test suite alone: expects the agent to rebuild the Docker stack, point the Telegram test bot's webhook at a fresh ngrok tunnel, and smoke-test the public URL end-to-end so he can look at the running bot himself ("rebuild docker ... setupkan telegram lhistore bot testing nyalakan ngrok agar saya bisa lihat langsung"). Confidence: 0.7
- When he does ask for a rebuild ("silahkan rebuild image"), treats it as the full local refresh loop rather than the build alone: rebuild the local image, apply pending migrations explicitly (`docker compose run --rm --no-deps app php artisan migrate --force`, required because the local stack runs with `APP_AUTO_MIGRATE=false`), recreate the containers, then confirm the app is healthy (`/up`), all supervisor workers are RUNNING, and the new schema columns/commands are actually registered — and mention that the image was built from the working tree, so uncommitted changes ride along in it. Confidence: 0.6
- Keeps the local Docker footprint pruned after such runs: unused containers/images/networks/build cache and orphaned anonymous volumes left over from earlier dogfood sessions should be removed to reclaim disk, while the live data volume is left intact. Confidence: 0.55

## Localization
- Every user-facing string ships in all supported locales (id/en/ms) with identical placeholders, validated by a translation audit test; localized English is the technical default while the product copy stays Indonesian/Malay-flavored. Confidence: 0.65
