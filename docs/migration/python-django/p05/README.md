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
or worker children. Each process owns at most one connection and keeps it for
`CONN_MAX_AGE=60` so a burst of updates does not pay a fresh PostgreSQL
handshake per request; `close_old_connections()` still returns an expired or
unusable connection. No pool is added. Readiness closes its connection after the
check. The role ceiling of six reserves four connections for the P10
HTTP/worker runtime (one HTTP and one per queue) and leaves two bounded
diagnostic/test slots; it is not permission to spawn six workers. The runtime's
own admitted ceiling remains four. PostgreSQL's existing `max_connections=30`
retains three reserved slots: budget one HTTP + four synchronized test processes
+ one coordinator + at most two operator connections = eight normal connections,
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
P12 extends this authority to registered historical supplier entitlements and
original-currency refunds through `OrderLifecycle`; supplier acquisition remains P18.
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

Bot checkout, receipt delivery and lifecycle/supplier/warranty work belong to
P08/P09/P12/P18 rather than the P07 proof. Actual production history/schema
coverage remains HD after H1. This port grants no production, real-money,
deployment or CI/CD authority.

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

For the separately authorized H1 navigation-to-receipt smoke, the operator must
attest a new scope containing `p08_create_limit: 1`, a positive integer
`p08_create_amount` in rupiah, and `p08_receipt_delivery: true`. The relay
rejects any create whose amount differs before reserving the attempt or reaching
the network. Existing P08 dual approval alone does not permit receipt uploads.
The relay persists the single create **attempt** before network I/O, including
ambiguous failures, and does not reset it on container restart or disposable-volume
reset. Do not delete the private create state to retry an ambiguous attempt. A
new attempt requires a separate operator authorization.

The integrated polling driver requires the running boundary's private startup
proof to match the approved scope and verifies that the application shares that
boundary's network namespace. The boundary image must match the locally built
source identity; an older or mismatched image is refused. The driver accepts
only the Testing Bot's approved private chat, `/start`, bounded catalog/quantity
navigation, QRIS selection and payment check. Balance, deposit and unrelated
service updates are refused before durable admission. It requires separately
managed workers and a restorable existing webhook. The operator must attest that
the private restoration record contains the **current** webhook secret and
that the previous webhook did not pin an IP address; Telegram does not expose
either fact. The driver compares observable webhook settings and restores them
after polling, but cannot independently verify the secret.

Building the shared P01 boundary tag affects future recreations even when the
existing container keeps its image ID. The P01 lifecycle guard refuses another
disposable stack while one is active. Do not replace that tag, stop the existing
stack, or bypass the guard without separate operator authorization.

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
exact-host HTTPS image or hosted presentations are supported. P12 now routes
authoritative provider expiry and due expiry/cancellation through the shared
`OrderLifecycle`; the earlier live observation does not qualify this later port.

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

## P09: credential receipts and outbound effects (#690)

`candidate/lhie_candidate/receipt/` uses the existing
`telegram_outbound_effects` table as the delivery authority. Paid checkout and
payment settlement register ID-only Redis wakeups after commit. The bounded
`candidate/receipt.py --once` consumer materializes receipt groups and claims
ordered units with token-fenced PostgreSQL leases. `--recover` discovers
missing groups and due units and repairs completed receipt projections without
sending again. Expired leases and lost acknowledgements remain `unknown`;
only definite initial photo failures may create the text fallback. A definite
custom-emoji rejection permits one Unicode retry; ambiguous results never do.
Historical invoice-photo groups retain their separate legacy successor rule.

The source checks Customer ownership, the latest paid Payment, late-payment
review, supplier state and externally handed-off fulfillment before a receipt
is created or sent. Retired allocations are not rendered. The authorized
allocation is decrypted only in process to render the invoice PNG, localized
text, TXT and action panel; jobs and effect metadata contain no credential
payload. The receipt queue is separate from admin/email work, and this path
does not pin messages. `receipt/grants.sql` is explicitly provisioned for the
disposable runtime; startup never grants privileges.

Local verification uses `python3 tools/migration_harness/smoke_checkout.py
--receipt` and `tests.test_receipt_render`, `tests.test_receipt_transport`,
`tests.test_navigation_ingress`, `tests.test_navigation_roots` and
`tests.migration_harness.test_p09_relay`. The smoke
uses synthetic checkout data, the repository PostgreSQL schema, Redis and a
fake-only Telegram relay. It checks one allocation/debit on replay, foreign
ownership, retired and partially retired allocations, manual review,
materialization rollback, concurrent claim fencing, lost ACK, definite photo
fallback, legacy invoice successors, action-panel and owner-bound TXT delivery,
rate limiting and sent-projection repair without a second send. Sanitized
evidence and source/image identities are in `.local/migration-p09/verification.json`.

For a fresh local run, build the required tags first:

```sh
docker build -t lhie-p09-candidate:local candidate
docker build -t lhie-p09-boundary:local tools/migration_harness
```

The verification image mounted the current source read-only over the existing
P05 image with Pillow 12.1.0 and DejaVu fonts added locally. A fresh candidate
build timed out while downloading Debian package indexes; it did not complete.
This is not evidence of a fresh production image, actual Telegram delivery,
provider qualification, production data/key coverage or visual parity.
Non-fake receipt uploads require
the `testing-bot` mode and a separately attested
`P09-approved-receipt-targets-only` scope; P06/P08 approvals do not authorize
credential sends. The relay verifies the configured safe chat, transmits uploads
in memory and treats uncertain upstream outcomes as ambiguous. No such send was
performed in this verification. Signed invoice links currently target the local
fixture; the real invoice surface belongs to P21. Previously issued
`txt:send`/`txt:cancel` confirmation nonces are not migrated from Laravel's
short-lived cache. The current PHP request handler no longer starts these
sessions; any pre-existing confirmation can still live for ten minutes. The
cutover must account for that TTL. Current `txt:request` actions remain
supported. Generic worker supervision and recovery scheduling belong to P10.

Context7 was consulted for Django 5.2
[transaction callbacks](https://docs.djangoproject.com/en/5.2/topics/db/transactions/#performing-actions-after-commit)
and [row locks](https://docs.djangoproject.com/en/5.2/ref/models/querysets/#select-for-update),
and PostgreSQL 16 [explicit locking](https://www.postgresql.org/docs/16/explicit-locking.html).
The [Telegram Bot API](https://core.telegram.org/bots/api#sending-files)
specifies multipart uploads and response semantics.

## P12: shared Order lifecycle and retained refunds (#694)

Implemented on `migration/p12-order-lifecycle-694`, based on
`migration/python-django-experiment`. Blockers #688 and #693 were closed before
implementation; H1's Owner decision permits continued local work, not production
or provider activation.

`checkout/lifecycle.py::OrderLifecycle` owns outer synchronous PostgreSQL
transactions for paid, pending expiry/cancellation, manual completion/rejection,
full/registered partial refunds and Manual Fulfillment Review resolution.
`CheckoutService` and `SakurupiahLifecycle` use this owner instead of independent
paid-state writes. Only deadlock/serialization failures retry, at most five local
attempts; provider observation and Telegram delivery stay outside those closures.
Public methods receive trusted authenticated identities from their adapters:
Customer cancellation rechecks the exact Order owner, manual operations recheck
the canonical role capability, and review/refund operations require Owner/Admin.
These methods are not new HTTP endpoints or a replacement for P19 authentication.

Recovery takes Customer shared lock, the original currency account for wallet
refunds, Order, all Payments ordered by ID, then Product and retained Supplier/SKU
resources before fulfillment/purchase, review, entitlement and credential rows.
`FulfillmentResources` includes immutable source snapshots, current mappings and
historical purchases in its ordered resource union. Uncertain purchases,
replacement/confirmation work and active leases block refund/release; a foreign
current-purchase pointer rolls back rather than cancelling another obligation.
Released holds retain their Supplier and original currency. New acquisition,
supplier transport, entitlement creation and provider-specific recovery remain P18.

Authoritative late money preserves a cancelled/expired Order, marks its Payment
paid and records one `late_payment` review. It neither records a sale nor allocates
or sends credentials. Owner fulfillment consumes available stock once; uncertain
external refunds retain their review and original transfer identity until explicit
confirmation. Expiry respects submitted manual proof, Binance grace and the
snapshotted BSC confirmation evidence. The existing payment worker repairs due
expiry in bounded, rotating pages after a lost wakeup. Provider cancellation
attempts remain durable on their original rail; their network executors belong to
the rail ports, not this transaction owner.

`checkout/refunds.py::OrderRefunds` consumes existing approved warranty/missing-unit
entitlements. IDR credits use `StoreBalanceLedger`; USDT refunds append to the
existing USDT ledger/accounting tables with the original debit fingerprint,
linked entry and cumulative cap. Full refunds credit only the remainder.
Externally funded refunds record exact original-currency transfer evidence, never
credit either wallet or execute a transfer. Pending/uncertain partials block full
remainder completion. Historical externally refunded Payments without
`OrderRefund` rows replay without manufacturing new evidence.

An IDR Order with a historical Binance/BSC USDT Payment keeps both snapshots.
Receipt validation uses the original receiver namespace or chain log/parent
claim and raw token precision, not an assumed QRIS beneficiary or an FX
conversion. Pakasir retains its historical completed projection; other rails use
their existing local-credential/review rules. History reads persisted status
through `OrderLifecycleProjection` without reinterpreting the old provider.

Refunded credentials stay allocated and retired. Credential reads validate the
remaining live quantity against confirmed missing-unit/warranty refunds, so a
healthy retained account remains accessible while unaccounted missing or retired
credentials fail closed. The full allocation signature still fences rendering.
One `usdt.refund_receipt` effect per credit commits with the refund, retaining
Laravel's effect identity, locale text and original invoice. The existing
`ReceiptProtocol` owns claims, bounded retries and lost-ACK `unknown` state.
The delivery pause defers this effect without blocking money recovery or
consuming an attempt. Maintenance/spending/restore barriers still govern new
checkout, not original-funds recovery.

### P12 verification and reuse

```sh
python3 tools/migration_harness/smoke_checkout.py --lifecycle
python3 tools/migration_harness/smoke_qris.py --integrated
(cd candidate && .venv/bin/python -m unittest \
  tests.test_order_projection tests.test_checkout_pricing \
  tests.test_navigation_checkout tests.test_checkout_fee_snapshot \
  tests.test_payment_sweep_timeout tests.test_workers \
  tests.test_sakurupiah_adapter tests.test_catalog_presentation \
  tests.test_receipt_render tests.test_receipt_transport)
```

This extends the existing P01/P03/P07 runner; it is not a second harness or report.
It uses the fake-only `lhie-p12-` namespace and records the shared private manifest
at `.local/migration-p12/verification.json`, including source/schema/image
digests. The observed run used Python 3.13.18, Django 5.2.18 and PostgreSQL 16.15
under `candidate_runtime`, with the current source mounted read-only over the
existing pinned P09 dependency image.

Observed: changed lifecycle E2E; synchronized paid-versus-cancel/expiry/refund;
two-worker original-IDR refund replay; final-audit rollback; due-expiry recovery;
partial plus remainder IDR/USDT accounting; retained healthy credential reads;
foreign-owner/entitlement/cap rejection; exact historical Supplier hold release;
PHP-generated Binance/BSC receipt and USDT fingerprint compatibility; and one
successful refund receipt plus an ambiguous ACK that was not resent. The focused
projection/checkout/navigation/worker/provider/receipt regression set passed
111 tests.
The existing integrated P08 callback/payment/receipt smoke also passed replay and
identity rejection, terminal release/late review, ordered credential delivery,
ambiguous delivery and bounded lost-wakeup recovery after the lifecycle cutover.
The PostgreSQL cases live in `candidate/tests/test_order_lifecycle_postgres.py`
and `test_order_refunds.py`; `test_order_projection.py` covers historical status
precedence and existing localized presentation.

These are synthetic database, provider and Telegram fixtures. They do not
qualify live custody/funding, actual historical data/keys, production performance,
new UI/auth adapters, deployment or CI/CD. The existing P07/P09 proofs remain
the shared owners of unrelated checkout and delivery scenarios.

## P18: supplier acquisition and historical entitlements (#698)

`candidate/lhie_candidate/suppliers/` operates on the existing supplier,
fulfillment, purchase, dispatch, account-unit and refund tables. Checkout can
explicitly receive the Laravel-compatible encrypter to reserve a ranked
supplier source after local stock. Product locks precede globally ordered
Supplier and SKU locks. Source, cost and conversion snapshots remain encrypted
or immutable, and supplier wallet and SKU commitments remain separate from
local stock. Catalog sync and administration use the separately provisioned
supplier operator connection; `operator_privileges.verify_operator_privileges`
checks its identity and grants before an operator consumer uses it.

`SupplierAcquisition` commits a claim and a dispatch episode before provider
I/O. Interrupted non-idempotent dispatches remain ambiguous with their holds;
the documented idempotent providers can replay the original reference once
within the bounded window. Known identities recover with an exact-order read.
`SupplierHistory` records bounded ToolOrAx history scans and CSxStore
candidate facts without automatically releasing holds. ToolOrAx automatic
scans cannot qualify an unverified timezone; Owner/operator no-debit evidence
and CSxStore candidate confirmation retain the original purchase identity.
Provider evidence and Owner no-debit attestation are distinct. Manual
credentials or an external handoff resolve customer delivery independently
of the original supplier economics. Historical per-unit and definitively
missing-unit refunds use the P12 original-currency refund owner; retired or
exposed credentials are not restocked. Provider-specific warranty claims,
replacement purchases, deposits and webhooks remain retired under ADR-0017.

`SupplierRecoveryWorker` runs bounded PostgreSQL discovery and token-fenced
acquisition in an isolated consumer with a caller-supplied client factory. It
is not part of the default supervisor or the existing four-connection budget:
starting it requires separate runtime admission and an explicitly qualified
transport. The default application installs neither supplier grants nor a live
transport, and does not start this consumer. Injected transports must enforce
the response-size and timeout limits, disable redirects and avoid logging
secrets. Do not point local tests at suppliers. Operator/dashboard adapters,
automatic worker composition and notification delivery remain with P23/P26
and P25 respectively; this slice does not qualify production suppliers.

The existing checkout harness provides the local changed-flow proof:

```sh
python3 tools/migration_harness/smoke_checkout.py --supplier
```

It provisions disposable PostgreSQL/Redis and a separate test operator role,
uses synthetic keys and fake transports, and records one source-bound manifest
at `.local/migration-p18/verification.json`. It exercises catalog sync and
draft import, partial/complete delivery through the bounded consumer,
exact-order and history-candidate recovery, ambiguous purchase review, Owner/provider evidence,
retained warranty and missing-unit refunds, retirement, foreign access and
competing checkout, acquisition and historical credential-refund claims.
The test also checks fixed supplier URLs, redirect refusal and retired webhook
routes. It does not qualify live provider contracts or actual historical data.

## P19: authentication, identity linking and ownership compatibility (#695)

`candidate/lhie_candidate/auth/` ports the existing Customer authentication
owners without reusing admin `users`, Django's default User, or a second
identity/challenge/mail ledger. Work is on `migration/p19-authentication-695`,
from integration revision `d7f4d45c`; blockers #693, #685 and #683 were closed.
H1's continue decision permits this local implementation, not production access.

`AuthService` owns pending registration, purpose/version-bound verification,
password login, first-password enrollment, Telegram email enrollment, OTP,
reset/revocation and retained magic links. It reuses P03's explicit
`sha384-v1`/raw bcrypt/Argon verification and Laravel encrypted JSON codec.
New Customer password writes use Laravel-readable bcrypt over the versioned
SHA-384 input; existing raw passwords rehash only after successful proof.
Admin verification uses the raw legacy hasher, never the Customer prehash.
Neither authentication nor linking moves money, receivers or Orders.

Google authorization-code state commits as consumed before provider I/O.
The fixed-endpoint adapter requires a verified subject/email, exact HTTPS
callback and configuration/browser binding; email collision requires explicit
fresh linking. Unlink requires another verified login method and preserves the
original freshness timestamp. The HTTP factory is unavailable by default;
disposable provider injection is not live Google qualification.

Telegram deep links use the P01 boundary's verified `getMe` identity, not a
separate username setting or ENV. `/telegram/identity` only reads the already
attested Testing Bot, caches success for one hour and failure for 30 seconds,
and returns no credentials. Fake mode supplies no bot identity. Reserved
`wa_`/`wa_confirm:` ingress is encrypted in the existing navigation inbox,
intercepted before Customer resolution, and erased after terminal processing.
Private actor preparation, one-use confirmation and originating-browser
consumption remain separate. Unknown actors are not auto-registered; canonical
Telegram conflicts never merge Customers. Telegram unlink remains unavailable.

### Guards, provisioning and H2 session policy

Explicitly provision `auth/schema.sql` and `auth/grants.sql` only in the admitted
disposable database, alongside the existing navigation/checkout grants.
Startup installs neither legacy tables nor auth metadata. Readiness admits the
reviewed auth column writes and identity deletion only; other public table-wide
writes remain denied. Short Customer-write transactions use the existing
transaction-local legacy trigger search path; the connection's default search
path remains `django_candidate`.

`django_candidate.browser_sessions` stores hashed random selectors and bounded
server-side metadata. Secure, HttpOnly, SameSite=Lax cookies support the Google
return without sharing Customer/admin authority. Authentication destroys the old
session selector and rotates CSRF state. Every protected Customer request checks
the current authentication version and applicable nullable-email provider proof;
admin requests independently recheck `users`, role, allowlist and password binding.
Admin limits retain five identity failures and ten IP failures per 60 seconds;
successful admin login clears its identity bucket without erasing IP failures.
Customer limits retain their existing separate bounds. Redis admission uses
atomic HMAC-keyed reservations and fails closed; OTP attempts remain durable.

The candidate policy proposed for H2 is explicit reauthentication for **both**
old PHP guards. PHP cookies/session payloads are never imported, deserialized or
accepted as candidate authority. Old browser-bound OTP/Google/Telegram proofs
must restart. Unexpired unbound verification/reset/magic capabilities remain
compatible; no startup sweep silently revokes them. Recovery Key hashes and
signed links remain unchanged. Old guest browser grants require invoice/key
re-entry, not a new key. Candidate reset revokes other Customer sessions and
outstanding Customer grants while preserving admin sessions and guest recovery.
H2 must approve this policy and reconcile actual hash/key coverage, the existing
admin allowlist and legacy application origin before cutover. No dual auth
writer or automatic production activation is authorized.

HTTP routes are contract adapters and bounded owned-Order metadata readers;
P21/P22 still own full storefront/dashboard presentation and credential pages.
Invoice/key recovery grants exactly 30 minutes. Paid guest claiming locks both
Customers in ascending order before the Order, rechecks existing ownership and
all account-identity evidence, and writes no Customer ID into an admin audit FK.
Laravel signed links retain exact raw-query MAC/expiry checks, with an explicit
trusted `CANDIDATE_LEGACY_APP_URL` setting for the old origin.

Confirmation GETs never consume capabilities. Their native POST forms use
`Referrer-Policy: same-origin`: Chromium otherwise sends `Origin: null` from a
`no-referrer` document and Django correctly rejects it. No referrer crosses
origins; owned-resource/JSON responses retain `no-referrer`. CSRF stays
session-bound and rejects foreign Origin and foreign/stale tokens.

### Mail and bounded recovery

`MailWork` writes the existing encrypted `customer_auth_mail_works` atomically
with its grant. A 120-second attempt-fenced lease keeps SMTP outside locks.
Retries reuse the same grant, cap at five attempts, and erase terminal payloads;
a stale successful sender cannot overwrite a newer attempt's retry state.
`python auth_mail.py --once` performs a bounded 100-work recovery in the admitted
namespace. Its transport is only P01's discard sink at `127.0.0.1:1025`, never
a logging or production mailer. Automatic notification scheduling/retention
keeps its P25/P26 owner; no worker family or scheduler is activated here.

### P19 verification and limits

```sh
python3 tools/migration_harness/smoke_checkout.py --auth
(cd candidate && .venv/bin/python -m unittest \
  tests.test_auth_core tests.test_auth_providers tests.test_auth_http \
  tests.test_http tests.test_navigation_ingress -v)
python3 -m unittest discover -s tests/migration_harness -p test_p09_relay.py -v
```

This extends the existing P01/P03/P07 runner, not a second harness/report.
The single private manifest is `.local/migration-p19/verification.json` with
source, PHP-owner, migration and image digests. Observed: Python 3.13.18,
Django 5.2.18 and PostgreSQL 16.15; real WSGI, Redis, SMTP sink and synchronized
PostgreSQL consumption/registration/subject-link/guest-claim races passed.
Already-admitted registration contenders bypass only the cache limiter fixture
to compete at the database uniqueness boundary. Financial, inventory, Payment
and Order-item snapshots stayed byte-identical. PHP-generated raw/sha384 hashes,
Recovery Key and signed invoice worked without reset or regeneration. Fresh
P03 bidirectional identity vectors also passed on the host.

The 45 focused candidate regressions and nine relay regressions passed.
The PostgreSQL smoke caught raw JSON-text session decoding; its retained
regression now proves authenticated reload and CSRF/logout behavior. A separate
synthetic browser fixture exercised the actual confirmation renderer at 390px
and 1280px, fragment autofill/scrubbing, visible keyboard focus, native submission,
invalid-token denial and no-JavaScript magic-link submission. No overflow was
observed; native black/white text measured 21:1. This browser fixture used
synthetic in-memory session/core boundaries, not live account/provider proof;
the positive auth/link/recovery-to-owned-read proof is the PostgreSQL/HTTP smoke.
Full visual parity and real-device qualification remain P21/P27.

Runtime source was mounted read-only over the existing locked P09 dependency
image. The fresh boundary build could not fetch Alpine packages with networking
disabled; the exercised P19 boundary instead overlays current source on the
cached P09 firewall runtime. Its exact image identity is in the manifest.
This is not a clean-image-build or performance qualification.

No production data/secrets, real Google/Telegram identities, customer mail,
provider activation, deployment or CI/CD was used. HD owns actual historical
data/key qualification; H2/H3 retain readiness, execution and retirement authority.

## P13: QRIS rails and historical Pakasir (#696)

`payments/routing.py` resolves new checkout from the current store setting and
all subsequent operations from the persisted Payment provider. Allowed new
providers remain Manual, KlikQRIS and Sakurupiah. Pakasir has no new-checkout
preparation or create method. This follows current PHP `PaymentProvider` and the
Admin switcher; P00's earlier KlikQRIS-retirement statement was incorrect.

`payments/settings.py` owns optimistic provider/channel version changes and
their audit records. Provider changes require Owner/Admin; Sakurupiah channel
changes require Owner and a unique active supported provider channel. Readiness
I/O precedes the setting transaction; checkout rechecks its preflight version.
The optional `payments/settings_grants.sql` is explicit operator/disposable
provisioning, never runtime startup or automatic activation.

### Authority, recovery and presentation

- Manual checkout freezes the database TTL and requires an admitted QR asset.
  `/payment <invoice>` accepts a photo/caption or photo reply only for the
  Customer's pending manual Payment. Duplicate proof cannot replace the first.
  Proof holds the reservation and writes one pending admin-notification effect;
  P12's authorized manual approval/rejection remains settlement authority.
  The photo itself never proves paid money. P25 owns actual proof delivery.
- KlikQRIS retains its encrypted signature, provider quote and QR evidence even
  when cancellation commits during provider creation. Dispatched failure is
  uncertain, not permission to create another invoice. Normal status dispatch
  reserves a durable 20-second interval; paid hints bypass only that interval,
  not 404/429 backoff. Missing/unknown status cannot declare unpaid money.
- Sakurupiah recovers unknown create by the original merchant reference even
  before a total/reference is committed. Retry uses the encrypted original
  request on that same Payment. A success indication is retained until
  authoritative history can confirm the immutable quote. Payment and merchant
  single-flight/cooldown fencing uses PostgreSQL; Redis is an optional hint/cache.
  This deliberately preserves P10 Redis-loss recovery instead of PHP's
  cache-unavailable deferral. It is not a provider fallback or performance pass.
- Historical Pakasir QRIS/HOSTED records keep their original identity and
  presentation. Cancellation uses the existing attempt rows, a 120-second
  abandoned-processing lease, at most three attempts, 30/120-second backoff, and
  independent detail checks before/after cancellation. A paid race claims the
  receipt and enters the shared late-payment review path, never unpaid success.
- Every automatic rail uses the existing external-money receipt registry and
  P12 Order/stock transitions. Callbacks are hints; authoritative observation
  must confirm identity, currency and amount. Provider changes, transport
  failure, expiry and cancellation cannot retarget an existing Payment.

`payments/presentation.py` reads admitted public storage and the existing
database QR-template settings. Missing Manual QR disables that rail. Native
payloads validate exact IDR/CRC before rendering. Template composition preserves
the detected QR matrix, whole-module scaling and quiet zone; unsuitable
decoration retains the original image. Admitted source images are limited to 1600 pixels per side and
5 MiB. Image retrieval is explicitly injected and accepts only trusted provider
URLs; default composition performs no new remote image request.
`qr-lhie.jpg` remains the original undecorated Manual asset. No icon, price,
template or other presentation environment variable was introduced.

### Environment and qualification inventory

| Rail/environment | Callback authenticity and trusted targets | Qualification blocker |
| --- | --- | --- |
| Manual | Owned private-chat proof; admitted `qr-lhie.jpg`; explicit operator approval | Actual QR/receiver and file-ID mapping, operator acceptance and P25 notification delivery |
| KlikQRIS sandbox | Persisted encrypted transaction signature; `/api/webhook/klikqris/sandbox`; fixed `klikqris.com/api/sandbox`; strict sandbox storage PNG paths | Authorized sandbox credentials, target attestation and actual Testing Bot/provider run unavailable |
| KlikQRIS production | Separate credential/base/callback checks; `/api/webhook/klikqris`; explicit HTTPS host allowlists | Production configuration, credential custody, endpoint evidence and owner release approval unavailable |
| Sakurupiah sandbox | Existing authenticated P08 relay; `/callback/sakurupiah`; fixed `sakurupiah.id/api-sanbox/`; strict QR assets and `sanbox-payout.php` | Earlier P08 evidence is not qualification of this changed revision; no new authorized external run |
| Sakurupiah production | Both production keys; Bearer/create signature and raw-body HMAC; `/api/webhook/sakurupiah`; fixed `sakurupiah.id/api/`, QR assets and `payout.php` | Defaults disabled/unqualified; actual production credential/endpoint qualification and release approval unavailable |
| Pakasir historical | Callback is unsigned by source contract; minimized hint requires independent authenticated detail; fixed `app.pakasir.com` detail/cancel/checkout URLs | Retained project/credentials, historical state coverage, serialized-job drain and owner retirement evidence unavailable |

`payments/runtime.py` still defaults to the existing P08 sandbox relay. KlikQRIS,
Pakasir and production Sakurupiah require explicitly injected adapters; none
constructs a live HTTP client, enables egress, changes a webhook, or activates
credentials here. The injected image boundary accepts generated image bytes in
fake mode only; live Telegram/Testing Bot media admission is unchanged.
Unavailable qualification blocks its HD/H2/H3/full-acceptance gate. Synthetic
success is local protocol evidence, not an external-provider pass.

### P13 verification

Build the current hash-locked dependency image before either QRIS smoke:

```sh
docker build -t lhie-p13-candidate:local candidate
python3 tools/migration_harness/smoke_qris.py --rails
python3 tools/migration_harness/smoke_qris.py --integrated
```

Both modes extend the existing P01/P07/P08 runner. The rail proof's single
private manifest is `.local/migration-p13/verification.json`, with source,
base-revision, harness and dependency-image identities. It exercises actual
PostgreSQL/Redis, bounded worker sweeps and Django callback routes against
synthetic transports. The shared integrated mode retains the original
create/callback/payment/receipt and real PostgreSQL lock races. Both commands
passed locally. The rail path also exercised durable photo-caption proof
intake, the actual Telegram checkout bridge, provider-switch replay, cancellation
presentation, lost-wakeup sweeps and same-invoice recovery. The generated QR
template was inspected and decoded; no Telegram client/device visual or live
image-upload qualification is claimed.

The initial 147 focused rail/presentation/navigation/worker regressions passed
in Python 3.13.18/Django 5.2.18 with locked dependencies and networking disabled.
Affected follow-up checks passed 87 payment/worker/HTTP tests, 28 Pakasir tests,
21 checkout/presentation tests and 12 navigation-boundary tests. The boundary
regression rejects generated-image uploads even with existing external-target
approval. Runtime source was mounted read-only over the freshly built dependency
image. The PostgreSQL proof caught second-precision provider deadlines and
cancellation-lease fencing; its retained scenarios cover those failures.

Focused contracts live in `tests.test_qris_selection`, `test_klikqris`,
`test_sakurupiah_p13`, `test_sakurupiah_production`, `test_pakasir` and
`test_qris_presentation`. Shared money/stock authority remains P07/P12 evidence,
not a repeated per-provider matrix. The external image-admission regression is:

```sh
python3 -m unittest discover -s tests/migration_harness -p test_navigation_boundary.py -v
```

No production data/secrets, real provider calls, real-money tests, customer
messages, provider activation, deployment or CI/CD operations were used.

## P14: Binance Pay and BSC USDT checkout verification (#697)

`checkout/external_usdt.py` prepares the customer-selected rail under the
existing checkout/setting locks. Readiness runs before those locks. Orders keep
their original IDR economics; Payments and retained item price snapshots use
exact micro-USDT. The amount allocator preserves existing Binance occupancy and
BSC's active-checkout/shared-funding exclusions. It never converts an IDR total
to USDT or enables a funding rail.

### Receiving configuration and immutable contracts

The additive `2026_11_05_000001_add_public_binance_checkout_receiver.php`
migration adds nullable public receiver type/value fields to the existing
payment setting. It copies neither ENV values nor historical Payment data.
`payments/receiving.py::PublicReceivingSettings` owns Owner-only, versioned
read/change/clear operations with locked role revalidation and an atomic masked
audit. Its explicit `receiving_grants.sql` is separate from ordinary payment
worker grants. Dashboard composition remains P23-owned.

New Order ID checkout requires the existing
`binance_order_id_compatibility_ready_at` marker. The canonical
`BinanceOrderIdActivation` owner switches the legacy amount index; startup and
the verifier never perform that activation. The disposable fixture explicitly
qualifies this schema contract without enabling any live provider.

Binance receiver/mode/automatic-verification fields are inserted with the
Payment, not patched afterward; the runtime cannot update them. BSC retains
the existing verified checkout receiver/claim, network, token, decimals, exact
token units, deadline, confirmations, finality and grace policy in
`payment_bsc_details`. Public receiving configuration is independent of provider
credentials, signing and custody. There is no application wallet key service.

### Verification, recovery and presentation

- `payments/binance.py` signs bounded history requests and strictly parses
  exact amounts and receiver identities. Amount-unique and Order ID verification
  share the same authoritative evidence; `transactionId` remains a lookup
  compatibility alias, never independent proof. Incomplete history, missing
  evidence and outages cannot cancel or fulfill an Order.
- `payments/bsc.py` is watch-only. Bounded log discovery finds a candidate;
  receipt, Transfer, canonical block, exact receiver/token/amount/window and
  frozen confirmation/finality checks remain authoritative. Historical
  transaction-hash Payments keep their submission path. Reobservations preserve
  the first transfer-evidence row and append durable events.
- Receipt claims use PHP-compatible canonical identities and fingerprints.
  Binance canonical/Order ID aliases share one uniqueness domain; BSC also
  claims the parent transaction across checkout and funding. Held claims keep
  their review reservations. Exact late money is retained for review, never
  automatic fulfillment, a second credit or a reopened terminal Order.
- PostgreSQL settlement relocks and revalidates the original snapshot and
  submission version after provider I/O. BSC writes use a transaction-local
  `pg_catalog, public, pg_temp` search path for retained public ownership
  triggers; the restricted session default remains unchanged.
- Configured external rails join the existing bounded payment-worker sweep.
  Binance recovery has a 30-second dispatch interval; BSC retains one-minute
  normal/five-minute provider-error eligibility. Redis remains a wakeup only.
  Text replies to root-bound Order ID/hash prompts also run on the payment
  worker, release the chat advisory lock for provider I/O, and revalidate the
  claim, session and canonical root before rendering.

The existing Telegram chooser, locales, copyable amounts/receivers, DB-backed
icons and blue button convention are retained. Instructions reload immutable
Order-item names and payment targets rather than current catalog/config values.
Pending, confirming, review and terminal views do not imply proof from a hint.
The shared lifecycle intentionally leaves these `provider=manual` paid Orders
in `processing`, matching PHP; receipt eligibility remains independent.

Default runtime composition still installs no Binance/BSC adapter. Existing
snapshots remain readable without admitting new targets. Explicit adapter
injection is a technical composition seam, not live qualification. BSC shared
address/amount reuse retains ADR-0003's documented late-transfer attribution
tradeoff; this port does not create per-invoice receivers.

### P14 local verification and limits

```sh
python3 tools/migration_harness/smoke_qris.py --external
python3 tools/migration_harness/smoke_qris.py --integrated
```
Both modes passed locally after the insert-only snapshot cutover. The external
manifest's source hashes were checked against the working tree with no mismatch.


The external mode extends P01/P07/P08 rather than creating another harness.
Its single private manifest is `.local/migration-p14/verification.json`, with
source hashes, base revision, dataset, harness and dependency-image identities.
The PHP schema oracle mounts current migrations read-only over its cached
dependency image; provisioning remains explicit and disposable.

The changed flow covers authoritative mismatch/outage/finality observations,
both Binance lookup modes, BSC discovery/historical hashes, receiver rotation,
stale hints, terminal late money, per-rail multiprocess settlement, cross-target
reversed Binance aliases, global BSC parent conflicts, credential receipt
delivery, Owner configuration rollback and runtime SQL privilege denial.
Shared cart/stock/wallet proofs remain P07/P12-owned.

Focused contracts are `tests.test_binance`, `test_bsc`,
`test_external_usdt_checkout`, `test_public_receiving` and
`test_navigation_checkout`, plus the affected worker/ingress and shared payment
regressions. No full-suite or performance qualification is implied.
Telegram surfaces were exercised through the real presenter/processor with
synthetic transport ACKs, not a native client/device visual run.

UI delivery gate, scoped to the ported Telegram surface:

- **Hard Gate PASS:** retained native message/button patterns; no new assets,
  visual framework or presentation ENV settings.
- **Purpose Gate PASS:** the selected rail, exact amount and immutable receiver
  remain the instruction hierarchy; no catalog or locale redesign.
- **Liveness PASS:** ENERGY/RHYTHM/MOTION 1/1/1; existing invoice identity,
  icons and button semantics retained, with no animation added.
- **Craftsmanship PASS:** chooser, owned prompts, refresh/review/terminal states,
  stale actions and provider uncertainty exercised with synthetic transports.
  Native-client contrast, keyboard and viewport qualification is not claimed.

No production mutation, live Binance/chain call, real-money test, customer send,
funding/custody activation, deployment or CI/CD operation is part of this proof.
HD/H2 retain actual history, receiver/provider and release qualification.

## P15: IDR funding, adjustments and reconciliation (#699)

P15 preserves the existing IDR ledger, source claims and effect ledger. Funding
policy changes use the database singleton, version fencing and transactional
audit; a funding intent keeps its policy snapshot. New manual, Sakurupiah and
KlikQris operations require explicit rail composition and configuration. Provider
I/O is outside money transactions. Settlement, receipt claims, evidence, audit,
balance and notification obligations commit together; compensated or already
claimed money cannot be credited a second time.
If a Sakurupiah create outcome is unknown and no original quote was persisted,
trusted success is recorded as `quote_conflict` for review, not credited from a
newly inferred quote. The configured payment worker sweeps durable funding
events and recoverable top-ups with bounded, rate-limited provider observations.


`adjustments/` requires a persisted Owner session, current Owner role and a fresh
password on every confirmation, including replay. Database-backed attempt
leases and lockout cannot be reset by Redis loss. Previews bind recipient,
amount, reason, session, credential version and source. Funded transfers require
an internally verified, customer-attributed IDR receipt; plain user-provided
references are not proof. Top-up and refund recovery reuse their original
resolution owners. Reversals reference an existing adjustment and cannot exceed
the available balance. Ledger history and accounting entries remain append-only.

Projection reconciliation is read-only and never repairs history:

```sh
python candidate/check_store_balance.py --customer 123
python candidate/check_store_balance.py --limit 100
```

These commands require the admitted candidate runtime and explicit read grants.
Without `--customer`, the result covers only the first `--limit` accounts ordered
by Customer ID, not the entire store. Money writers reject inconsistent
projections rather than silently correcting them. Investigate a reported mismatch
through the established operator process.

Local qualification uses:

```sh
python3 tools/migration_harness/smoke_navigation.py --p15
```

It creates only labeled disposable infrastructure, synthetic records and fake
provider transports. Its evidence is `.local/migration-p15/verification.json`.
The local PostgreSQL 16 proof passed for policy snapshot/drift rejection,
settlement versus compensation, linked reversal versus spending, refund caps,
revoked sessions and password lockout, receipt and BSC-parent conflicts, audit
rollback, manual and KlikQris decisions, forged/duplicate callbacks, lost
wakeups and provider-outage throttling. The focused funding, payment adapter,
refund and worker suite passed 75 tests.

The run is not live-provider, production-data, native-client, notification-delivery
or deployment qualification. Legacy KlikQris top-ups without an authoritative
persisted provider environment fail closed; historical environment binding must
be qualified before those rows can safely recover. Admin/customer Dashboard
adapters and delivery of notification obligations retain their downstream owners.
No runtime grants are installed automatically.

Context7's Django 5.2 transaction guidance was consulted: keep synchronous
`transaction.atomic()` boundaries around the complete database operation and
discard `on_commit` wakeups when a transaction rolls back. Redis wakeups are not
the durable source of money or notification state.

## P16: USDT ledger, wallet selection and receipt ownership (#700)

`lhie_candidate/usdt/ledger.py` posts source-bound purchase debits and linked
full reversal debits to the existing USDT account, entries and accounting event
tables. `checkout/refunds.py` remains the original-currency refund owner.
Matching source/fingerprint replay resolves the existing posting; changed
payloads conflict. `ApprovedUsdtReversal` mirrors the PHP internal source:
its reference is supplied by an already-authorized caller, not proof of
approval. No operator or customer endpoint invokes it. A future caller must
provide and verify its own durable approval authority.

For preference checkout, `BalanceCheckoutRequest` validates all supplied
candidate price consents before selecting a single full-payment wallet. Both
automatic candidates are checked under locks in IDR-before-USDT order;
frozen or drifted accounts, spending pauses and an open/missing restore gate
fail closed. USDT totals and snapshots are integer micro-USDT, never converted
from the IDR Order economics. Raw token units reject sub-micro remainders and
BIGINT overflow. Reconciliation compares signed entries and projection/cursor
in one snapshot; drift freezing is an explicit, locked recheck. Accounting
aggregation uses the existing per-event consumer claims and leg tables.

The existing receipt owner also accepts verified USDT top-up identities and
checks BSC parent claims before consumption. Receiver address claims remain
permanent and channel-exclusive; the ownership lookup includes retired Customer
bindings. These are registry interfaces, not evidence of provider verification,
custody, receiver provisioning or authorization to activate a funding rail.
Verified funding, provider observation, settlement and their durable resolution
remain P17; no arbitrary USDT funding credit is exposed here. Explicit local
`checkout/grants.sql`, `payments/grants.sql` and `usdt/grants.sql` provisioning
is required and never runs at startup.

Local proof: `python3 tools/migration_harness/smoke_checkout.py --p16` uses the
existing disposable harness, PHP-generated synthetic ledger entries and PostgreSQL
16.15. `.local/migration-p16/verification.json` binds the dataset, schema,
dependencies and source hashes. It exercises USDT purchase and original-currency
refund replay, reversal replay, aggregation, rolled-back drift injection and
freeze, stale consent, frozen secondary wallet, competing wallet debits,
cross-target BSC parent/receipt claims, Binance aliases and receiver ownership
races. The focused candidate regression command covers 40 tests. Neither
synthetic execution nor available registry code qualifies real custody,
historical production data, external providers or funding activation. Python
advisory locks do not fence PHP processes; the approved cutover must maintain
one writer authority.

Context7 guidance: Django 5.2
[atomic error boundaries](https://docs.djangoproject.com/en/5.2/topics/db/transactions/)
require handling database errors outside the relevant atomic/savepoint block;
PostgreSQL 16 [explicit locking](https://www.postgresql.org/docs/16/explicit-locking.html#LOCKING-DEADLOCKS)
requires consistent lock order. No provider I/O belongs in these money closures.

## P17: USDT funding, finality and operations (#701)

`FundingService` atomically binds a Customer's canonical Telegram root, policy,
immutable target and one active reservation. Expiry and cancellation release the
slot without a credit; later verified deposits are claimed to the original target,
kept in manual review and shown in history. Existing immutable expiry/cancel
resolutions are not rewritten: the legacy Owner resolution API rejects an
already-resolved target, so later money needs an operator-led disposition rather
than an automatic credit or refund.

`FundingSettlement` leases preparation and observation work; provider calls run
outside transactions and successful evidence is rechecked under database locks
before the existing receipt claim, ledger posting, resolution and outbound effect
commit together. Binance history and BSC watch-only finality are injectable
adapters, not activation decisions. BSC scanning persists bounded checkpoints;
reorg evidence freezes exposed accounts before the incident worker records
available and spent exposure. Owner-bound recovery preserves postings and leaves
the hold unresolved. A recognized drift freeze can be cleared by an authenticated
Owner only after a locked reinspection, and never while reorg evidence exists.

`OperationalReconciliation` walks all account, receipt and unreceipted-credit
pages in a repeatable-read snapshot. Custody providers are an explicit injected
registry, empty by default; missing, unavailable and unsupported backing are not
zero. `RestoreGate` requires completed settlement/scan replay, quarantined
ambiguous outbound effects, a subsequent complete reconciliation and zero
unexplained drift. Pause scopes block new exposure rather than these recovery
paths. Alert episodes and customer receipts use the existing effect ledger.
The customer return authorization binds Customer, target and canonical root;
it does not transfer funds. Owner Dashboard composition remains with P23.

The additive `2026_10_30_000001_restore_usdt_funding_resolution_constraints.php`
repairs conflicting historical PostgreSQL checks for unpaid release and reviewed
compensation. Its rollback refuses data that the older checks cannot represent.
Explicit `usdt/funding_grants.sql` and `usdt/operations_grants.sql`, together
with their prerequisite grants, are disposable provisioning only and are never
applied at startup. Default worker composition supplies no funding or custody
provider; an injected readiness declaration is not evidence of human qualification.
External custody/signing, receiver rotation, production changes and live-money
operations remain outside this issue.

Local proof: `python3 tools/migration_harness/smoke_checkout.py --p17` uses the
existing disposable PostgreSQL 16 harness and synthetic Binance, BSC and Telegram
adapters. It covers paused settlement, concurrent settlement/delivery dedupe,
cancel/expiry and late review, Owner authorization, finality and spent reorg,
scoped alerts, multipage reconciliation and restore refusal. The generated
`.local/migration-p17/verification.json` records the synthetic scope and source
hashes; it is not live-provider or custody qualification.

Context7 guidance: Django 5.2 [transaction callbacks](https://docs.djangoproject.com/en/5.2/topics/db/transactions/#performing-actions-after-commit)
run after the outer commit and are discarded on rollback; PostgreSQL 16
[`SKIP LOCKED`](https://www.postgresql.org/docs/16/sql-select.html#SQL-FOR-UPDATE-SHARE)
is suitable for queue claims, not a complete reconciliation snapshot.

## P20: Customer API v1 (#702)

`candidate/lhie_candidate/customer_api/` provides the bearer-only v1 routes,
legacy selector/SHA-256 key format, database availability fence, shared Redis
admission and token-owned order permits. Key rotation and checkout both lock
the Customer before the credential. API order claims remain keyed by Customer,
operation and idempotency key across rotations. The accepted Order, isolated
API Cart, wallet debit, inventory allocation and claim acceptance share the
existing checkout transaction; external supplier work stays outside it.

Catalog, balance and order reads use explicit response projections. Delivery
holds the owned Order shared, rechecks the existing paid-receipt authority,
reads only the requested credential chunk and uses Laravel-compatible encrypted
continuations. Page continuation does not change whole-Order progress.
API and Telegram recovery share the paid-ownership gate: an open late-payment
review or undelivered supplier fulfillment blocks credential reads. Other open
reviews remain visible in Order progress, while already allocated non-retired
units are recoverable as in Laravel. Retirement between pages removes that
unit from subsequent reads; confirmed refunded units must be retired and remain
unavailable. Automatic receipt effects use the stricter complete-allocation gate
and reject any open review, so a partial recovery read cannot mark fulfillment
or a receipt complete.

Disposable provisioning requires the existing checkout, payment, receipt and,
when explicitly testing supplier-backed checkout, supplier grants, plus
`customer_api/{credential_grants,orders_grants,reads_grants}.sql`. Startup never
applies grants. The default HTTP composition does not inject a supplier
acquisition capability; trusted composition can inject the encrypter after
separate qualification. No live supplier or funding provider is enabled here.
The Python and Laravel limiters share budgets only when explicitly configured
to use the same Redis database, effective Redis/cache prefix and Laravel app
key. P01 uses isolated Redis DB 0; it is not evidence of cross-runtime shared
admission. Cutover must preserve one writer and qualify the actual configuration.

Inside the admitted candidate runtime, `python customer_api_usage.py --once`
drains at most 500 usage fields. It is intentionally not scheduled by startup.
P26 must schedule bounded drains; until then counts can expire after ten minutes
and are lower bounds, not financial authority. Store Bot key-management callbacks
and their private actor/root checks remain P24; callers of these credential
services must enforce
the key-operation limiter and private navigation authority.

Local verification: `python3 tools/migration_harness/smoke_checkout.py --api`
uses synthetic PHP-generated keys and cursor, PostgreSQL 16, Redis and a
loopback WSGI server. It exercises catalog/balance, IDR and USD purchases,
concurrent same-key attempts, conflict/replay, key-rotation fencing, disabled
admission, foreign and tampered delivery cursors, review gating, retired stock,
supplier pending with explicit synthetic injection, and bounded usage drain.
It also buys 100 maximum-length structured accounts through real PHP intake,
then recovers them across 1 MiB-bounded pages without another debit. Structured
checkout follows Laravel's identity registry: email alone is not a unique
identity, and accounts without an auth token need no registry identity.
Source identity and limits are recorded in `.local/migration-p20/verification.json`.
The separate `--receipt` and `--supplier` smokes exercise the shared recovery
and supplier authorities. From `candidate/`, run `.venv/bin/python -m unittest
tests.test_customer_api_contract tests.test_checkout_pricing
tests.test_wallet_selection tests.test_money tests.test_receipt_render`.
These proofs do not qualify historical production keys, live providers,
production deployment or CI/CD.

Context7 `/django/django/5.2.18` was consulted for
[nested atomic blocks](https://docs.djangoproject.com/en/5.2/topics/db/transactions/):
database exceptions must be handled after the appropriate savepoint has rolled
back. Network calls and permit admission remain outside the money transaction.

## P21: Storefront and customer pages (#703)

`candidate/lhie_candidate/storefront/` owns the HTML adapters. Catalog reads use
the existing navigation repository; checkout prices, stock, payment preparation
and transitions remain with the checkout and payment owners. A review GET
stores only browser-session metadata and creates no Customer, Cart, Order or
Payment. The signed review binds quantities, prices and a same-origin return
destination to the browser; submit rechecks shared pricing and inventory.
Recovery Keys are shown
once, hashed on the Order and never put in a URL. Recovery by invoice and key
is paid-only, as in Laravel; authenticated claims remain explicit and cannot
merge Customers by email.

Signed invoice actions use the P19 signature and ownership checks. Credential
delivery reads through the paid receipt owner. Resend queues the existing
`credentials_ready` event against its saved encrypted recipient, and refuses
ambiguous delivery outcomes rather than silently sending again. The P25 mail
worker owns actual notification delivery; the web response only reports that a
request was queued. Supplier and non-default payment adapters must be explicitly
injected after qualification. The local image reader serves only verified
public image files from the admitted disposable storage root; homepage banner
dimensions use the same admission and the existing 16:9–4:1 aspect limits.

Owned order history is Customer-scoped, filterable by status and paginated.
Customer balance history merges funding targets and ledger entries in bounded
25-row pages. Cursors use the Laravel-encrypted, Customer- and currency-bound
format; history is read-only and does not change the balance projection.
Signed-in account pages retain the profile sidebar, Customer-scoped order
counts and ledger-checked balance summaries alongside the account navigation.

Catalog and product support links use the configured WhatsApp and Telegram
contacts. Invoice support offers the existing issue categories and sends only
the invoice number and selected issue in the prepared message, never a signed
URL, Recovery Key or credentials. The chooser works without JavaScript.
The ChatGPT login guide retains its original step-by-step copy, images and
invoice return context; contextual invoice pages remain private and no-store.

Apply `storefront/catalog_grants.sql`, `storefront/grants.sql`,
`storefront/account_grants.sql`, `storefront/balance_idr_grants.sql`, and
`storefront/balance_usdt_grants.sql` explicitly alongside the prerequisite
checkout, payment, receipt and auth grants; startup does not grant privileges.
Local verification uses the existing synthetic P01/P07 fixture and disposable
PostgreSQL/Redis: `python3 tools/migration_harness/smoke_checkout.py --storefront`.
It checks review side effects, signed URLs and CSRF, stale price/stock/cart,
checkout replay and lock admission, cancellation, paid recovery/claims, safe
resend and Customer-scoped balance pagination. The `--storefront-browser` variant
exposes only the loopback disposable fixture for
interactive checks. Neither qualifies a real payment provider, mail delivery,
historical production data, deployment or CI. Waitlist delivery remains P25;
full visual/browser acceptance remains P27.

Context7 `/django/django/5.2.18` and the Django
[CSRF documentation](https://docs.djangoproject.com/en/5.2/ref/csrf/) were
consulted for session-bound CSRF and same-origin browser requests. Templates
retain Django autoescaping; customer and invoice responses are private/no-store.
