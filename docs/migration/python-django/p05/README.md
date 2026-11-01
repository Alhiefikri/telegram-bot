# P05: isolated Django bootstrap (#683)

Authority: [#683](https://github.com/Alhiefikri/telegram-bot/issues/683),
[live canonical parent #679](https://github.com/Alhiefikri/telegram-bot/issues/679),
[P00 inventory](../p00/README.md), and [P01 admission](../p01/README.md).
Both blockers #680 and #681 were closed before implementation. Work belongs on
`migration/p05-django-bootstrap-683-ready`, based on the updated
`migration/python-django-experiment` (`339539bb`), not `main`.

This is the local foundation, not a port of checkout, authentication, catalog or
customer dashboards. Laravel production entrypoints, Compose and workflows remain
unchanged. No migrations, workers, scheduler, provider activation or webhook setup
run automatically. No production data, keys or credentials are needed. The live
parent's synthetic-first decision takes precedence over older frozen P00/local
planning copies that require a production snapshot. HD #684 follows H1; it does
not block this bootstrap or synthetic P03 work.

## Supported dependency contract

Selection checked against official sources on 2026-10-30:

| Dependency | Pin | Support decision |
| --- | --- | --- |
| CPython | 3.13.18 | Stable security release; 3.13 security support ends October 2029 per the developer guide |
| Django | 5.2.18 | Supported LTS, extended security/data-loss support through April 2028 |
| psycopg / psycopg-binary | 3.3.4 | Stable PostgreSQL adapter, CPython 3.13 support; no independent LTS promise |

`candidate/.python-version` and the candidate Dockerfile pin Python;
`requirements.in` pins direct dependencies and `requirements.lock` pins every
resolved transitive dependency and distribution hash. Install with hash checking.
The first limiting framework horizon is April 2028; update supported patches and
rebuild/reverify before that date. A pinned version is reproducibility, not a
promise that it stays secure without maintenance. No speed guarantee follows
from framework selection. Python's release page has an inconsistent October 2028
sentence; the version lifecycle table is the horizon used here.

Sources: [Django supported releases](https://www.djangoproject.com/download/),
[Django Python compatibility](https://docs.djangoproject.com/en/5.2/faq/install/),
[Python release](https://www.python.org/downloads/release/python-31318/),
[Python lifecycle](https://devguide.python.org/versions/),
[psycopg release metadata](https://pypi.org/project/psycopg/3.3.4/).
Context7 was consulted via its public library-resolution and context API:
`/websites/djangoproject_en/5.2`, separate queries for
[per-thread connections](https://docs.djangoproject.com/en/5.2/ref/databases/),
[error-report filtering](https://docs.djangoproject.com/en/5.2/howto/error-reporting/),
and synchronous transaction boundaries. Sensitive POST decorators alone do not
cover URLs, arbitrary exception text, headers or nested payloads; the bootstrap
instead uses payload-free diagnostics and generic responses.

Regenerate intentionally, review dependency changes, then run all lanes below:

```sh
uv pip compile candidate/requirements.in --python-version 3.13 --generate-hashes --output-file candidate/requirements.lock
uv venv --python 3.13.18 candidate/.venv
uv pip sync --python candidate/.venv/bin/python --require-hashes candidate/requirements.lock
```

## Ownership and dependency direction

One Django application; no duplicate ledger, effect store or generic repository
framework. Existing canonical owners and ADRs retain authority until their own
migration ticket proves parity. Modules do not imply activation.

| Boundary | Canonical authority retained | Python successor |
| --- | --- | --- |
| Catalog / presentation | Family/Product/Plan, DB icons, pricing and locale snapshots | P06 #687; no catalog ENV settings |
| Checkout / Order Lifecycle | Idempotent intent, lifecycle, cancel/expiry/refund | P07 #688, P12 #694; service owns synchronous atomic transaction |
| Inventory | Credential reservation/allocation/sale/retirement | P07 #688; never edit stock projections directly |
| IDR / USDT / receipt claims | Existing ledger/history, source claims, reconciliation | P07 #688, P15 #699, P16 #700; no float or second authority |
| Payments | Preflight, immutable invoice, authoritative observation | P08 #689, P13 #696, P14 #697; network outside locks |
| Telegram | Canonical root/update dedupe and existing effect ledger | P06 #687, P09 #690; preserve unknown delivery, no blind resend |
| Customer / owner / API auth | Separate existing identities and object ownership | P19 #695; no automatic merge or framework-admin replacement |
| Supplier / warranty | Unknown purchase and historical entitlement | P18 #698; disabled suppliers remain disabled |
| Operations | Recovery, scheduling, audits and safe health | P10 #691, P26 #708; bounded isolated capacity |

Implemented foundation lives in `candidate/lhie_candidate`: domain contracts are
framework-independent; injected Protocol ports receive trusted facts and return
explicit outcomes. HTTP, Telegram and job adapters must delegate to the same
service, never wrap business writes in independent transactions. Pure purchase
assessment is not a debit or permission to fulfill. Future money writers use one
synchronous `transaction.atomic()` service closure, deterministic lock order and
bounded retries of DB-only work. Never retry network calls within money locks.

Python job envelopes contain versioned stable identities only. They carry no ORM
objects, money snapshots, credentials or serialized PHP payloads. The consumer
reloads authoritative state and delegates to the owning service; Redis is only a
wakeup. Stable ID does not by itself grant authorization or replace durable
idempotency/lease/fencing. Worker runners remain P10, not an idle placeholder
process shipped here.

## Local startup and schema admission

Use only P01 lifecycle commands, never production Compose. All endpoints remain
inside the P01 default-deny namespace; the host publishes candidate HTTP on
`127.0.0.1:18802`. The candidate has no bot/provider token. SMTP remains the
namespace-local discard sink, and transport capabilities remain P01's explicit
fake/attested testing-bot/sandbox boundary. There is no general outbound client.

```sh
python3 tools/migration_harness/harness.py init candidate
python3 tools/migration_harness/harness.py validate candidate
python3 tools/migration_harness/harness.py build candidate
python3 tools/migration_harness/harness.py start candidate
curl --fail http://127.0.0.1:18802/health
curl --fail http://127.0.0.1:18802/ready
python3 tools/migration_harness/harness.py manifest candidate
python3 tools/migration_harness/harness.py stop candidate
```

`init` intentionally refuses existing config. For an existing P01-only candidate
volume, explicitly reset its **disposable** data before P05 startup so PostgreSQL
runs the candidate-only role/schema initializer. Never apply it to legacy data or
silently upgrade an existing restore. `stop` retains data; `reset` destroys only
P01-labelled disposable volumes and leaves source archives untouched.

After P01 admission and the sticky-quarantine check, the harness projects only
local dataset/identity/endpoints/limits into private `candidate.json` and creates
a separate random private `django-key` in its ignored state directory. Neither
shell ENV nor .env files override application settings. Missing/unknown fields,
nonlocal endpoints, raw datasets, quarantine and broadened concurrency fail
closed without echoing values. Bind-mounted keys are read-only; do not publish
state/Compose/key files. Startup validates exact money contracts and database
identity/privileges plus Redis PING before binding HTTP. Readiness fails safely
when dependencies become unavailable; liveness does not disclose connection data.

Fresh candidate PostgreSQL creates `candidate_runtime` without superuser,
createdb or createrole and with a four-connection role ceiling, plus the additive
`django_candidate` schema. Runtime search path never includes legacy `public`.
The P01 `harness` role is a disposable administrator/test setup role, not the
application role. No Customer/admin tables are remapped, created or migrated.
Framework auth/admin/session apps are not installed and the migration boundary
refuses unqualified schema writes; enabling metadata/auth later requires P19's
reviewed additive schema and compatibility contract. Django admin is not a
customer/owner dashboard.

Templates use Django's escaped server templates and staticfiles/collectstatic;
plain CSS/vanilla JavaScript only. No page, navigation, assets or customer-visible
redesign is introduced by P05. Asset collection is a build/development step,
not a reason to expose arbitrary files with DEBUG or a production dev server.

## PostgreSQL connection and process budget

The local HTTP server is deliberately one process and one thread with no reload
or worker children. `CONN_MAX_AGE=0`; no pool is added. Each request can hold at
most one runtime connection; readiness closes its connection. The role ceiling
of four leaves bounded room for manual diagnostics, not permission to spawn
four background workers. PostgreSQL's existing `max_connections=30` retains
three reserved slots: budget one HTTP + four synchronized test processes + one
coordinator + at most two operator connections = eight normal connections,
leaving nineteen normal slots unused. Concurrency smoke is a diagnostic alongside
HTTP, never benchmark load. Future queue allocation must explicitly account for
processes × threads × aliases/pools, keep slow work separate from receipts and
interactions per ADR-0030, and stay inside P01's total 2 CPU/2048 MiB cap. Host OS,
Docker and test drivers are outside that cap. This server is not a throughput or
production-deployment choice.

## Test entry points

Pure rules and boundary tests run without production or a database:

```sh
cd candidate
.venv/bin/python -m unittest discover -s tests -v
cd ..
python3 -m unittest discover -s tests/migration_harness -v
```
If uv has no binary for the selected security-only Python release, use the
hash-locked Docker image instead of silently substituting an older interpreter:

```sh
docker run --rm --network none --read-only --tmpfs /tmp --cap-drop ALL --entrypoint python \
  lhie-p05-candidate:1 -m unittest discover -s tests -t . -v
```


Real PostgreSQL concurrency runs **inside the already admitted candidate app**:

```sh
docker compose --env-file /dev/null -f .local/migration-p01/candidate/compose.json exec -T \
  -e CANDIDATE_POSTGRES_SMOKE=disposable-local-only app python smoke_postgres.py
```

This explicit test-only opt-in is not a runtime content setting or credential.
The harness refuses endpoint overrides and uses synchronized spawned processes,
real transactions and a unique disposable fixture schema. It proves one winning
claim and rollback behavior, then removes only its own schema. It is not proof
of migrated money, inventory or receipt races; those tickets must reuse this
harness with their real owners and invariants. No SQLite lane substitutes for
PostgreSQL. Fault injection remains disposable/local. Never run fault tests on
an operator-acquired quarantine or production database.

Full P05 runtime acceptance (requires the built image and default P01 candidate
configuration) starts and resets only the labelled disposable candidate:

```sh
python3 tools/migration_harness/smoke_candidate.py
```

It exercises actual HTTP error/HEAD behavior, five secret-safe startup refusals,
Redis loss/recovery, runtime schema-write denial, template escaping/static
collection and the PostgreSQL multiprocess harness. P01's separate
`python3 tools/migration_harness/smoke.py` covers both stacks' cgroups, transport
isolation, alternation, persistent stop, quarantine and reset boundaries.

Use P01's smoke for real cgroups, egress/mail isolation, alternation and sticky
quarantine. Record sanitized revision, dependency/image versions, dataset identity,
commands, outcomes/skips/unknowns and evidence paths; do not retain ENV, payloads,
request strings, exception repr or credentials. See [verification.md](verification.md)
for the observed run and its limits.

## P07: atomic checkout, inventory and IDR (#688)

Implemented on `migration/p07-atomic-checkout-688`, a child of
`migration/python-django-experiment`. Blockers #683 and #685 are closed.
The shared service lives in `candidate/lhie_candidate/checkout/`; production PHP,
deployment configuration and workflows remain unchanged.

### Transaction and idempotency contract

`CheckoutService.checkout(CheckoutRequest(...))` owns its outer synchronous
`transaction.atomic()` boundary and refuses an already-open caller transaction.
Adapters supply the authenticated Customer, owned Cart, stable checkout key and
the server-held or authenticated `PricingSnapshot` accepted at confirmation.
They must not re-quote at submit and silently replace the Customer's consent.
The snapshot includes Product/quantity, effective IDR/micro-USDT prices,
subtotals, applied IDR tier and retail/reseller context. Reseller authority comes
from the trusted service constructor, never the submitted snapshot.

`quote_cart()` is advisory; checkout reconstructs and compares the entire
immutable pricing snapshot under Product locks. Tier validation and independent
reseller overrides preserve the existing pricing owner. Money and arithmetic use
bounded exact integers, including base quote, per-item and aggregate overflow
checks. P07 only charges IDR and does not convert or relabel historical currency.

Accepted key replay verifies Customer/Cart ownership and returns the existing
Order and Payment before current price, stock, guard or provider readiness checks.
The Cart lock also makes accepted-Cart replay first-wins across changed keys.
The Telegram key helpers preserve PHP's encoded-application-key HMAC payloads,
including rail-independent payment-choice prompts and detail revision identity;
update IDs do not create new checkout keys. API callers retain their separate
`api-` claim namespace; the API adapter remains P20.

Lock order for Store Balance:

1. Customer shared row, then Cart exclusive row.
2. Canonical IDR account, verified against signed history and latest entry.
3. Shared spending-policy and restore rows; missing policy/restore fails closed.
4. Maintenance singleton, Cart Items, ascending Products, then payment settings.
5. Newly created Order/Payment/Items, ordered local credential allocations,
   reservation proofs, one original-Order debit and inventory sale proofs.

Spending pauses, open restore state and maintenance reject new spending. Funding
activation and funding/rail pauses do not authorize or prohibit original-order
refund recovery. Only the trusted payment preparation's `preflight()` runs
outside the transaction; `prepare()` is transaction-local and must not perform
HTTP/RPC, Telegram or queue I/O. Stateful stale preflight fails atomically.
At most five complete local transaction attempts handle deadlock/serialization
failures and the named checkout/reference unique conflicts. Arbitrary integrity
failures are not retried, and preflight is never repeated by that loop.

### Canonical inventory and balance ownership

`InventoryLedger` requires caller-owned atomicity for allocation, reservation,
sale, release and retirement. It uses existing Products, Product Stock Items and
`inventory_movements`, with the original `(Order, Product, type)` claims.
Actual local credentials must cover every unit; numeric-only or supplier capacity
does not manufacture fulfillment. Sale consumes stock and reserved stock once.
An unpaid release clears only unretired allocations and releases the reservation.
A sale cannot be released back to available. Retirement keeps allocation and
credential identity history, without consuming or restoring stock again.

`StoreBalanceLedger` writes only `customer_balance_accounts` and
`store_balance_entries`. Purchase postings derive the amount and ownership from
the IDR Order/Payment and use `order:{id}:debit`. `refund_order()` is a trusted
lifecycle prerequisite, not a Customer refund endpoint: it locks account before
Order/Payment, verifies the original paid/refunded IDR source and debit, subtracts
registered partial refunds, and posts at most the remaining original amount under
`order:{id}:refund`. It does not change lifecycle statuses or release resources.
Supplier-backed refunds fail closed pending the supplier/lifecycle port.
Historical source claims replay without rewriting their original idempotency key.
No arbitrary credit, parallel ledger, automatic projection repair or funding
activation is exposed.

Explicit disposable `checkout/grants.sql` adds column-limited writes after the
P03 schema oracle. Startup never installs it. Readiness accepts only the reviewed
navigation/checkout write allowlist; runtime cannot update/delete ledger history,
change financial snapshots or spending/restore/maintenance values, or create
public tables. Metadata timestamp UPDATE grants allow PostgreSQL row locks
without giving the runtime permission to edit guard values. Ordinary IDR entry
immutability relies on runtime privileges plus existing model guards; the legacy
adjustment-history trigger is not a universal superuser immutability guarantee.
Existing positive/nonnegative, source/idempotency unique, refund-source, FK and
immutable balance-selection snapshot constraints remain authoritative.

### Shared verification and downstream reuse

```sh
python3 tools/migration_harness/smoke_checkout.py
docker run --rm --network none --read-only --tmpfs /tmp:size=64m --cap-drop ALL \
  --mount type=bind,src="$PWD/candidate",dst=/opt/candidate,readonly \
  --entrypoint python lhie-p05-candidate:1 -m unittest \
  tests.test_checkout_pricing tests.test_money tests.test_purchase \
  tests.test_legacy_fields tests.test_runtime tests.test_http
```

The smoke reuses P01/P03 under its own `lhie-p07-` fake-only prefix and private
`.local/migration-p07/lifecycle` state. It leaves attested Testing Bot configuration
and retained P06 volumes untouched, runs no automatic workers, and resets only
its disposable volumes. The PHP fixture creates encrypted synthetic stock through
canonical intake, funds through the existing IDR ledger and supplies a real PHP
tier-checkout outcome. Spawned PostgreSQL processes prove same-wallet contention,
last-stock ownership and duplicate checkout recovery. The same proof exercises
stale inputs, spending/restore/maintenance gates, final-step rollback,
release/retirement/original-source refund, database privilege/constraint denial,
bounded retries and stale preflight. Later consumers reference this proof rather
than cloning it.

The single run manifest is `.local/migration-p07/verification.json`: dependency
image/version, base revision and exact source/legacy-owner/migration digests,
synthetic dataset identity, outcomes, P01 manifest and limits. Source mounts are
read-only over the existing dependency image; this is correctness evidence, not a
new image-build or performance qualification. Failed diagnostics stay private.

Observed local P07 verification: Python 3.13.18 / Django 5.2.18 against
PostgreSQL 16.15 passed the shared proof, including three-attempt serialization
recovery and the five-attempt ceiling with one preflight. The focused candidate
regressions passed 34 distinct tests; two runtime tests initially lacked writable
temporary storage and passed on a targeted rerun with `/tmp` tmpfs. The offline
Docker rebuild could not fetch dependencies, so the smoke used the existing
dependency image and current read-only source. Disposable P07 containers and
volumes were reset after the proof.

Sandbox/Testing Bot checkout, receipt delivery and full lifecycle/supplier/warranty
work remain P08/P09/P12/P18. Actual production history/schema coverage remains HD
after H1. This port grants no production, real-money, deployment or CI/CD authority.

Context7 consulted Django 5.2
[atomic error boundaries](https://docs.djangoproject.com/en/5.2/topics/db/transactions/)
and [row locking](https://docs.djangoproject.com/en/5.2/ref/models/querysets/#select-for-update),
plus PostgreSQL 16
[consistent lock order and deadlock recovery](https://www.postgresql.org/docs/16/explicit-locking.html#LOCKING-DEADLOCKS).
The Context7 sources were `/websites/djangoproject_en/5.2` and
`/websites/postgresql/16`; no dependency change was needed.

## P08: Sakurupiah sandbox payment slice (#689)

The candidate uses the active-provider inventory's Sakurupiah rail, not retired
KlikQRIS or Pakasir. `checkout.sakurupiah.SakurupiahPreparation` requires the selected
setting and a fresh observed active channel before P07 creates the Order and
Payment. It snapshots the channel with the existing Payment, reference, amount
and local deadline. Post-commit `payments.lifecycle.SakurupiahLifecycle` operates on that
Payment; it never creates a replacement or falls back to another provider.

`payments.sakurupiah.SakurupiahSandboxAdapter` sends through `payments.relay.SandboxRelay`.
The P01 boundary holds the sandbox credential, signs requests and permits only
the four documented `https://sakurupiah.id/api-sanbox/` POST operations. It
refuses redirects, private DNS resolutions and unapproved phone, callback and
return targets. A timed-out or otherwise ambiguous create remains
`outcome_unknown`; it is not repeated blindly. A recovered invoice without a
previously committed provider total remains `recovery_unconfirmed`.
If a response has valid invoice facts but no safe presentation, the Payment
retains its provider identity and amount for later verification; it is never
presented as payable.

Provider HMAC is verified on the original callback bytes in the boundary.
An independent internal secret protects forwarding to the candidate; the
candidate coalesces minimized hints in the existing `payment_events` table.
Callbacks alone cannot mark a Payment paid. `consume_callbacks()` serializes
per Payment and performs provider status and amount-bearing history lookups
outside database transactions; settlement re-locks and revalidates the original
snapshot. The existing external-money receipt and alias claim, inventory sale
and paid transition share one transaction. Late/cancelled payments claim their
receipt and enter manual review without releasing credentials.

Enablement is explicit and disposable only: provision P06/P07 schema and
`payments/grants.sql` separately; application startup never grants them. A
P08 callback configuration adds an operator-attested scope, with sandbox API ID,
credential, synthetic phone, exact HTTPS callback/return URLs and safe chats
kept in the private P01 state. `p08-sandbox-testing-bot` additionally requires
the distinct `P08-approved-dual-targets-only` scope and verifies the exact
Testing Bot identity before startup. The callback listener publishes only
`127.0.0.1:18803`; the operator must separately supply a restricted HTTPS
forwarder and verify that it cannot reach production. No tunnel, webhook
mutation, scheduler or payment worker starts automatically.

For an operator-approved dual-target run, the private P01 candidate config must
include all five P08 fields: `p08_callback` with gate
`P08-approved-sandbox-callback-only`, `p08_api_id`, `p08_phone`,
`p08_callback_url` ending in `/callback/sakurupiah`, and `p08_return_url`.
Set `mode` to `p08-sandbox-testing-bot`, `sandbox_status_url` to the exact
`https://sakurupiah.id/api-sanbox/` base, and `sandbox_token` to the protected
sandbox key. `approval.scope_sha256` covers every config field except `approval`;
the operator must recompute it after attesting all targets. The generated
internal callback secret is separate from both provider and bot credentials.
Do not put credentials, test numbers, callback secrets or full URLs in Git,
shell history, logs or issue comments.

`harness.py p08-draft candidate` can prepare a private, **unapproved** sandbox
draft without changing the active configuration. Supply only the two existing
`SAKURUPIAH_SANDBOX_API_ID` and `SAKURUPIAH_SANDBOX_API_KEY` variables in its
environment, and pass a protected JSON file containing `phone`, `callback_url`
and `return_url` on stdin. The URL fields must use the same temporary
`ngrok-free.app`, `ngrok-free.dev` or `trycloudflare.com` host; the callback path
must be `/callback/sakurupiah`. The draft does not attest credentials, identify
the bot, activate the callback or compute approval. Independently verify the
sandbox credentials and targets before the operator approves the final scope.

An ephemeral HTTPS tunnel is suitable only while this isolated sandbox is running.
For example, Cloudflare's [Quick Tunnel](https://developers.cloudflare.com/tunnel/get-started/quick-tunnels/)
needs no account and forwards to `http://127.0.0.1:18803`; its hostname changes
when restarted. The URL is publicly reachable, and the tunnel operator can see
traffic: only the bounded HMAC-verified callback listener may be exposed. Do not
use an email challenge, since the provider cannot complete it. Check the chosen
tunnel's billing and account limits before starting it; never point it at a
production service or change a Telegram webhook.

### Qualification and limits

Prior [merchant sandbox observations](../../../agents/sakurupiah-api-research.md#recommended-verification-sequence)
showed channel listing, create, history, status and an app-linked Laravel
settlement; the provider paid callback was not observed and one app-linked
create omitted usable presentation. Those historical observations are not a
qualification of this Python adapter or the current merchant configuration.
On 2026-11-01, an operator-attested candidate run used an isolated synthetic
database, the exact sandbox endpoint, a temporary ngrok HTTPS callback and the
separately verified `@LhieStoreTestBot`. Sakurupiah reported QRIS active with
limits Rp500–Rp2,000,000. One Rp500 checkout created one Payment with a QR-image
presentation. A separate private Testing Bot message was accepted. After the
operator marked that invoice successful, one authenticated provider callback
arrived; manually consuming it checked both status and amount-bearing history,
then committed `paid`, one receipt claim and one inventory sale. The Order
became `completed`; reference, amount, provider, channel and deadlines remained
unchanged. A subsequent checkout and callback-consumption replay returned the
same Payment without a second Payment or sale. An invalid callback signature
through the public tunnel received HTTP 403.

This is a service-driven checkout and a separate bot delivery smoke, **not**
an interactive Testing Bot checkout: P06's payment button is not yet connected
to this service and durable checkout-instruction delivery belongs to P09.
Automatic callback recovery belongs to P10. Interactive checkout-to-paid
therefore remains unverified. No production credential, real payment, Telegram
webhook mutation or CI operation was used. Sanitized run evidence is in
`.local/migration-p08/verification-live.json`; the temporary tunnel was stopped.

Status responses may omit the amount, so matching history remains mandatory.
The single live run does not qualify rate limits, outages, duplicate provider
callbacks, retries or expiry behavior. Raw EMV QR payloads are rejected; only
exact-host HTTPS image or hosted presentations are supported. Provider expiry
is observed but does not release the Order or reservation; full expiry and
cancellation ownership is P12.

Local verification uses `python3 tools/migration_harness/smoke_qris.py` and the
focused `tests.test_sakurupiah_adapter`, `tests.test_p08_callback`,
`tests.migration_harness.test_admission` and
`tests.migration_harness.test_p08_relay` regressions. The smoke provisions the
repository schema and synthetic P07 fixtures in its own disposable `lhie-p08-`
prefix, invokes the actual checkout/lifecycle against a Unix-socket provider
fixture and writes one private `.local/migration-p08/verification.json` linked
to the P01 manifest. It does not contact the provider or Testing Bot. The
shared P07 stock/wallet proofs remain in `.local/migration-p07/verification.json`.
The smoke used the existing dependency image with current source mounted
read-only, not a live relay. Separate candidate and boundary check images built
successfully with `docker build`; the candidate regressions passed in the built
image and the boundary module loaded. These check tags do not update P01's
runtime tags: rebuild the admitted images before any operator-approved external
smoke. An offline `--network none` build could not fetch uncached dependencies.

Context7 documentation consulted: Django 5.2
[atomic transactions](https://docs.djangoproject.com/en/5.2/topics/db/transactions/)
and [row locks](https://docs.djangoproject.com/en/5.2/ref/models/querysets/#select-for-update),
and PostgreSQL 16 [explicit locking](https://www.postgresql.org/docs/16/explicit-locking.html).
