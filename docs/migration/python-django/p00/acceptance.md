# Acceptance scenarios, evidence requirements and gaps

Canonical authority is #679, not this summary. All parity rows remain unproven until their
successor tickets supply behavioral evidence. Unknown production facts below are explicit
handoffs, not blockers to publishing the P00 source inventory, and never waive full parity.

## Evidence record required per scenario

Record the scenario/matrix row, revision, command, expected and observed behavior,
dataset/config identity, failures/skips/unknowns and evidence limits. Keep shared
versions, manifests and resource settings in one run record and link it from
consumer issues; do not repeat the entire manifest for every scenario.
Reuse evidence only while the relevant code, schema, dependencies/config and
dataset remain applicable. Never store credentials, customer identifiers, raw
production dumps/ENV, secret Telegram payloads or decrypted files.

## Comparison contract (P11; refresh at P28)

P04 supplies a reusable isolated comparator, initial smoke and honest partial
baseline evidence. The owner approved stopping its long standalone benchmark:
the complete 24-case comparison belongs to P11 after Django is runnable, not a
prerequisite for port implementation. Reuse compatible PHP evidence and collect
missing/changed cases there. No 30% claim is allowed from P04 smoke or partial data.

- Transitions separately: Home→catalog, catalog→Family/Plan, Plan→detail, Back, Home,
  quantity changes, Orders read and Balance read. A transition outside the first slice remains
  pending for full parity; it is not deleted from the contract.
- The owner's 2026-10-31 implementation-first revision supersedes the former
  960-cell and staged 140-case requirements. Use one current-PHP baseline:
  eight transitions × users 1/5/10 = 24 cases per implementation, with a matching
  representative dataset, warm distinct-chat traffic and representative background.
- Each case uses two independent batches of 25 measured attempts, after five
  excluded warmups per batch. Keep failures/unknowns, not a quota of successes.
  No minimum-200 qualification, three-batch minimum or automatic extension.
  These exploratory samples do not establish production p95 confidence.
- Normalized PHP, small/cold/same-chat/idle conditions and other diagnostics are
  optional investigations of a named failure/risk, not mandatory matrices.
  Same-chat correctness remains covered by focused functional regressions.
- Preserve attempts/failures/outliers and report per-case p50/p95/max, errors,
  unknowns and existing CPU/memory/OOM/restart/backlog observations. Reuse tooling;
  no new sensor/dashboard/resume framework is needed for acceptance.
- Alternate stacks under equal **whole-stack** limits <=2 CPU/2 GiB, including
  representative workers. Disclose OS headroom and comparator differences.
  Normalized PHP never silently replaces the current-PHP baseline.
- Per transition/load p50_python <=0.70×p50_php **and** p95_python <=0.70×p95_php
  remains the target. Near-floor, threshold-adjacent, unstable or failure-heavy
  results are inconclusive. At most one targeted two-batch rerun after diagnosis
  or a fix; remaining uncertainty goes to H1 for revise/stop, not endless sampling.
- Checkout/paid-delivery timing comes from transaction-flow smoke, without a
  separate load matrix or invented numerical target. Old run artifacts retain
  their original identities and qualification; never pool incompatible protocols.
- Schema-4 runner defaults to diagnostic smoke; `--benchmark` explicitly opts
  into the P11 comparison workload. Sampling completion does not imply stable
  p95 or a comparison pass. Never resume incompatible protocol/source identities.
- Measure webhook ACK and screen edit/send ACK separately. Backend ACK is not device-visible
  latency. Record fake transport, provider sandbox and live Testing Bot in separate reports;
  do not flood Telegram. Human visible checks/video on Testing Bot remain H1 evidence.
- Checkout: submit→instructions-ready/ACK. Payment: authoritative paid time if trustworthy,
  observed→commit→queued→pickup→first credential ACK→complete group. Unknown timestamps
  stay unknown; disclose polling cadence and callback-vs-polling differences. H1 must set
  currently unspecified checkout/detection/delivery numerical budgets before expansion.

## Correctness, security and presentation acceptance

P03/P07/P09/P12–P26 reuse relevant prior assertion semantics, not framework internals.
Implement the changed flow, run its actual E2E/smoke, then focused regressions for
consumer-visible risks. No test-count/coverage quotas or full-suite-per-issue rule.
The following races/faults are a risk inventory, not a matrix for every ticket.
Prove shared protocols once at their owner; consumers link still-applicable proof.
P11/P28 exercise integrated gaps and changed boundaries, not another complete suite.
Use disposable real PostgreSQL and synchronized multiprocess barriers for last-stock,
same-wallet spending, duplicate update/checkout/API claims, paid-vs-cancel/expire/refund,
callback replay/order, two effect claimants, supplier uncertain purchase and challenge/link races.
Assert immutable economic sources/receipt uniqueness, ledger/projection equality, stock
reservation/allocation/retirement, currency/amount, ownership and outbound group/token/sequence
after each race. SQLite, mocks, arbitrary sleeps or source list counts cannot establish this.

Fault scenarios: kill before/after commit or transport ACK, lost Redis, stale lease/fencing,
partial receipt, definite/ambiguous transport failure, provider timeout/429/5xx, deadlock,
connection/disk pressure, restart and external evidence replay. Unknown delivery stays reviewable;
recovery cannot blindly resend or duplicate purchase/credit. Fault injection stays local/disposable.

Security scenarios: cross-customer objects on each channel; separate admin/customer/API guards;
CSRF, HTML/entity/XSS handling, callback authenticity/replay, shared fail-closed rate limits,
SSRF/media allowlists, key/digest/crypto integrity, secret-free errors and least-privilege DB writes.
P28/H2 need no unresolved critical/high issues; lower findings require explicit disposition.

P27 preserves copy/id/en/ms, emoji fallback, primary/danger buttons/footer icons,
menu order, media and animation. Reuse P21–P25 proof; inspect remaining gaps at
390px mobile and 1280px desktop, adding widths at changed breakpoints or observed
overflow. Check alternate themes/locales and keyboard/focus/zoom/no-JS/reduced
motion where the changed behavior needs them, not a Cartesian presentation matrix.
All features still need evidence. HTML snapshots alone do not prove interaction;
real Android/iOS/Telegram QA remains human.

## Explicit unknown/gap register and owner

| Gap | Missing evidence / completion requirement | Successor |
| --- | --- | --- |
| G01 Production activation | Per-feature/provider/funding/supplier mode/settings evidence with date and operator; source presence cannot substitute | H0 #684; P00 refresh; H2 #712 |
| G02 Actual data/schema | Live schema/version/extensions/triggers/checks/partial uniques/FKs/sequences/roles/grants/timezone/collation; quarantine restore and sanitized state/cardinality manifest | P02 #682, H0 #684, P03 #685 |
| G03 Historical coverage | Pending/late/expired/manual/review payments, old buttons, retired suppliers/pins, refund entitlements, raw serialized job variants; enumerate safe counts and compatibility disposition | P03 #685, P12 #694, P13 #696, P18 #698, P26 #708, P29 #711 |
| G04 Crypto/auth | All encrypted casts/Crypt callers, legacy serialization/key coverage, password/recovery/API/HMAC/cursor/state digests, session/cookie formats; synthetic bidirectional tamper/rotation vectors; explicit relogin policy | P03 #685, P19 #695, H2 #712 |
| G05 File references | uploads/storage/public, banner/cover/QR/tutorial/proof/receipt refs, private storage and Telegram file IDs; manifest integrity and Testing Bot reupload mapping without production overwrite | P02 #682, H0 #684, P21 #703, P25 #707 |
| G06 Measurements | Installed versions/host conditions, dataset sizes, timing resolution, sample variance, actual queue/worker memory and latency; current fake latency prior art is insufficient | P01 #681, P04 #686, P11 #692 |
| G07 Human budgets | Checkout/paid/delivery budgets and visible bot acceptance remain unset; no implied numeric pass | H1 #693 |
| G08 Qualification | Sandbox success cannot authorize provider/funding/custody activation, real money or customer messaging | P13–P17 #696/#697/#699/#700/#701, H2 #712 |
| G09 Browser and public clients | Full current route/callback/API/doc compatibility and browser/device behavior; legacy pin benchmark scenarios excluded only for retired automatic feature | P20 #702, P24 #706, P27 #709 |
| G10 Restore/cutover | One writer; old app understands Python rows during rollback; no PHP job unserialization/global Redis purge; fresh DB continuity, files/key custody, drain/replay, <=30 minute rehearsal incl rollback | P26 #708, P29 #711, H2 #712, H3 #713 |
| G11 Source expansion | Search anchors are not a semantic parser: review every route group/middleware, Telegram dispatch branch, model/migration/job/command, asset/config/test in inventory during each owning ticket; record split rows/new gaps, never treat unclassified supporting source as excluded | P03 #685; P06–P26; P28 #710; P31 #715 |
| G13 Stale ADR/glossary | ADR-0006 and the Native QRIS glossary describe new KlikQRIS selection, conflicting with current provider-switch policy; ADR-0020 still says not implemented while adjustment services/tests exist. Current code/instructions govern; retain historical context and reconcile decision text before port qualification, without implicit activation | P13 #696, P15 #699, P00 refresh |
| G12 Main drift | Baseline is immutable; new behavior/schema/ADR and comparator changes require new manifest, matrix updates and affected evidence before gates | all successors; P31 #715 |

## Context7 evidence used for this contract

Queried 2026-10-30 using `resolve-library-id` then `/django/django` (official source,
high reputation). First transport attempt failed; retry resolved and queries succeeded.
Version selection remains P05, not a P00 runtime choice. Main-branch documentation is guidance,
not a lock on the future Django version; repeat against the selected supported version at bootstrap.

- [Legacy database integration](https://docs.djangoproject.com/en/stable/howto/legacy-databases/)
  and [inspectdb](https://docs.djangoproject.com/en/stable/ref/django-admin/#inspectdb): generated
  models are a reviewed starting point, default unmanaged; defaults/field assumptions and deletion
  behavior need inspection. Schema authority and non-ORM constraints require separate evidence (G02).
- [select_for_update](https://docs.djangoproject.com/en/stable/ref/models/querysets/#select-for-update)
  and [TransactionTestCase](https://docs.djangoproject.com/en/stable/topics/testing/tools/#transactiontestcase):
  SQLite does not prove row locking; TestCase's enclosing transaction can mask autocommit errors.
  Use real PostgreSQL transaction tests plus synchronized processes for race evidence.

P00 adds documentation and an offline inventory utility only. No runtime Python, production
access, provider activation, UI changes, CI/CD run, workflow change, deployment or issue closure
is part of this change. H0/H1/H2/H3 remain explicit human gates.
