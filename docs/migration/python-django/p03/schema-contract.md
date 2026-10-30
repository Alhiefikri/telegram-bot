# P03 schema, roles and rollback contract

Authority: [#685](https://github.com/Alhiefikri/telegram-bot/issues/685),
[parent section 5](https://github.com/Alhiefikri/telegram-bot/issues/679),
[P02 catalog acquisition contract](../p02/contracts.md#5-schema-ownership-grants-and-sequence-capture).
This is a repository-generated PostgreSQL baseline, never an observation of production.

## Schema ownership and reviewed mappings

`candidate/lhie_candidate/legacy.py` contains deliberately unmanaged compatibility
projections for Customers, Customer Identities, USDT account snapshots and BSC
transfer units. They are not installed apps, not startup imports and not business
write services. The BSC class is a two-column read projection, not a complete
transfer writer. Other tables retain their PHP/domain owners until their own port.
`MetadataRouter.allow_migrate()` remains deny-all. The probe exercises Django's
actual `CreateModel.database_forwards()` gate with the unmanaged model and checks
that no relation is created. Calling a low-level schema editor directly is not an
authorized bypass.

| Baseline source / property | Python decision and boundary |
| --- | --- |
| `2026_06_22_000001_create_customers_table.php`, nullable/account/auth additions | Explicit signed `bigint` IDs, nullable Telegram/email/password, no inferred account enrollment or Customer merge. PostgreSQL bigint is signed even when Laravel calls it unsigned. Preserve 64-bit IDs without JSON/JavaScript floating-point conversion. |
| `2026_10_11_100000_add_customer_authentication_contract.php` | Identity provider/subject uniqueness, RESTRICT FK and canonical Telegram triggers remain database authority. `DO_NOTHING` avoids Django emulating a cascade that differs from the database. |
| `2026_10_11_110000_create_customer_auth_mail_works.php` | Customer password scheme defaults to `raw`; auth/enrollment versions default to zero. Use `db_default`, not application-generated replacement values. |
| `2026_10_05_170000_create_customer_usdt_balance_tables.php` | Currency `USDT`, zero balance/version defaults, RESTRICT ownership and nonnegative checks stay intact. `ExactBigInteger` rejects bool/float/string/overflow for money. Initial fixtures include one zero snapshot and one exact large balance with matching ledger/accounting history; no existing balance projection is repaired or updated. |
| `2026_08_24_000005_create_bsc_payment_transfer_evidence_table.php` | `numeric(78,0)` stays `Decimal`, including all 78 digits independent of the process Decimal context. `ExactUnits` rejects floats, fractions, nonfinite values and precision overflow. No implicit FX or money rounding. |
| `src/config/app.php` | The repository application zone is **Asia/Jakarta**, not WITA. Laravel `timestamp(0) without time zone` is local wall time. `LegacyTimestamp` accepts aware instants, converts to Jakarta and truncates subseconds to Laravel's second precision; reads attach Jakarta and return UTC. New additive metadata may use Django UTC timestamptz separately. HD must establish historical zone/config drift. |
| Laravel serial/sequence default | Explicit imported IDs do not advance sequences. Restore `last_value`/`is_called` under the schema owner; preserve empty-sequence semantics and state ahead of `MAX(id)`. Runtime gets USAGE/SELECT, never sequence UPDATE/setval. Gaps on failed transactions are valid. |

The rehearsal executes **all current repository PHP migrations** against an empty
P01 disposable database. This is not a hand-written substitute schema or SQLite
approximation. The resulting schema-only dump retains constraints, indexes,
functions, triggers, extension declarations, ownership and ACLs. The report pins
every migration source SHA-256, base revision, versions, dump digest and artifact
location. The dump contains no row contents or role password definitions. It is
an audit baseline, not an auto-executed production installer. The private dump's
pg_dump restrict token may vary; use its recorded checksum for that run.

## Least-privilege local role mapping

| Role | Scope |
| --- | --- |
| P01 `harness` | Disposable setup/schema owner only. Generates the source baseline, grants fixture permissions, seeds consistent initial snapshots/history and probes owner-side triggers. Not a runtime identity. |
| P03 `p03_compat_runtime` | Synthetic-only LOGIN, NOSUPERUSER/NOCREATEDB/NOCREATEROLE/NOINHERIT, two-connection limit. SELECT/INSERT only Customers/Identities; column UPDATE only `customers.updated_at` so the canonical identity trigger can lock its Customer `FOR UPDATE`; SELECT only account/BSC projections; USAGE/SELECT only the two required sequences. No broad future-table grants. |
| P05 `candidate_runtime` | Unchanged. No public-schema business-write grants are activated by P03. Downstream writers must propose per-operation grants, including sequences, and keep append-only histories immutable. |

The real DB probes deny CREATE/ALTER, unauthorized Customer UPDATE, DELETE,
ledger/projection updates, role escalation and sequence reseeding. They exercise
RESTRICT/missing FK, Telegram ownership and immutable-binding guards without
disabling any trigger/constraint. Schema-owner authority can override guards;
that is not a runtime guarantee. Production role names, memberships, PUBLIC and
default ACLs, extension versions/security-definer functions remain **unknown**
until HD. Do not copy these fixture grants into production or infer actual
least-privilege adoption from the existing privileges runbook.

The initial identity INSERT failed under SELECT/INSERT-only grants: its unchanged
source trigger uses `SELECT ... FOR UPDATE`, which PostgreSQL also requires
column UPDATE privilege to execute. Granting only `customers.updated_at` fixes
that legitimate lock requirement without granting ownership-column or money
writes, changing the trigger, disabling guards or introducing SECURITY DEFINER.

## Rollback writer scope

Python writes two synthetic Customers and one canonical Telegram Identity using
reviewed ORM mappings and Laravel-compatible defaults, password hash and wall
timestamps. A failed Customer+Identity pair rolls back both writes. The PHP
reader loads the Python-created row through the real Eloquent Customer model,
checks the original hash without rehashing, exact large Telegram ID, local
instant, provider binding and unchanged zero balance. Crypto and identity vector
lanes separately prove bidirectional storage/protocol encodings. This proves the
P03 compatibility seam, **not** approval of checkout/stock/money/auth endpoint
writes. Every downstream writer must prove its own old-app statuses, payloads,
financial history and durable obligations before the rollback window opens.

## Additive upgrades and Python-only bootstrap design

One schema owner; never two independent migration systems managing the same
tables. Before cutover, keep the existing reviewed legacy schema authoritative;
Python models remain unmanaged. New Django framework metadata belongs in the
separate `django_candidate` schema, with an explicit reviewed migration history
and separate deployment/schema-owner credentials. New columns/tables must be
additive and usable or ignorable by the rollback Laravel image. Do not rename,
drop or rewrite legacy identifiers, hashes, source fingerprints or immutable
financial/history rows to make a migration pass.

For the final no-PHP installer, promote a reviewed schema-only PostgreSQL baseline
from the pinned migration/catalog manifest into a versioned SQL artifact plus
Python migration history. Review executable SQL, owners/search paths, extensions,
FK actions, partial indexes, sequence dependencies and ACLs before promotion.
An empty-install command under the migration role verifies database identity and
empty target, applies the baseline transactionally where PostgreSQL permits, and
records its immutable baseline version/checksum. Existing databases instead
verify the catalog against that version and apply only reviewed additive deltas;
never run the fresh installer on populated data. Framework metadata is tracked
separately; no fake migration can silently bless a mismatched catalog. Sequence
state is restored separately from the schema artifact and checked before writes.

P26/P29 must implement and rehearse that installer/upgrade/rollback path without
PHP/Composer/Artisan before final retirement. P03's PHP migration oracle is
intentionally local-only and is not claimed to satisfy final PHP-free operations.
Destructive schema changes wait until the rollback window is explicitly closed.

## Context7 references

Consulted Context7's public library resolution/context APIs on 2026-10-30:

- `/websites/djangoproject_en/5.2`, legacy database integration: [inspectdb is a starting point; managed=False](https://docs.djangoproject.com/en/5.2/howto/legacy-databases/).
- `/django/django/5_2_6`, database field defaults: [db_default](https://docs.djangoproject.com/en/5.2/ref/models/fields/#db-default). Runtime stays pinned to Django 5.2.18; the available 5.2 Context7 source snapshot is older.
- `/websites/postgresql/16`, [sequence privileges and setval semantics](https://www.postgresql.org/docs/16/functions-sequence.html), [default privileges](https://www.postgresql.org/docs/16/sql-alterdefaultprivileges.html). Table grants do not grant sequence UPDATE; a runtime does not need setval.

## Required HD handoff (#684)

After H1 explicitly continues, separately authorized operators compare the
**actual** schema/version/catalog/ACL/extension/sequence/timezone baseline and
historical hash/key/identity coverage, retaining safe counts only. Unknowns are
not success, and synthetic success cannot close HD. No production connection,
backup acquisition, credential reset, Recovery Key regeneration, deployment,
provider activation or CI/CD execution is authorized here.
