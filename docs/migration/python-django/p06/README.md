# P06: initial private Telegram navigation (#687)

Implementation branch: `migration/p06-catalog-navigation-687`, based on
`migration/python-django-experiment` at `1ff329720768bacb9331c91771dc45f70a37c96e`.
Blockers #685 and #683 are closed. This slice does not activate production,
checkout, payment rails, automatic workers, webhooks or CI/CD.

## Boundaries

- `candidate/lhie_candidate/navigation/ingress.py` validates and minimizes updates;
  `webhook.py` authenticates `/webhook/telegram` with a purpose-separated HMAC of
  the candidate's private Django key. The secret never enters a response or log.
  Callback ACK is distinct from a completed screen.
- `navigation_updates` uniquely owns each Telegram update ID. Its bounded manual
  consumer claims before I/O, serializes each chat with a PostgreSQL session
  advisory lock and does not overtake earlier accepted pending updates. Late older
  updates are superseded. A processing/unknown update is not replayed blindly.
  P10 owns automatic workers and interrupted-work recovery; `/start` remains the
  explicit way to reopen an uncertain navigation surface.
- `roots.py` owns ACK-backed private roots, generations and DB-clock leases.
  Ownership requires the exact canonical Customer/Telegram/chat binding.
  Stale or foreign callbacks cannot render. Expired or replaced claims cannot
  commit a screen. Definite edit failures can replace once; ambiguous sends/edits
  cannot. Refresh deletes before sending and retains the documented failure risk.
- `presentation.py` and `repository.py` port Home, catalog, category filtering,
  Family/Plan, single-Plan shortcuts, detail, Back, quantity tiers and custom
  quantity input. Content and icon slots remain database-backed. Whole Product
  icon overrides replace whole inherited Family pairs. Purchase-time Order-item
  snapshots remain P07-owned, as they are not part of the PHP navigation session.
- id/en/ms copy is packaged from the PHP locale catalog. Customer/Product content
  is escaped before HTML interpolation; bound custom-emoji metadata uses UTF-16
  offsets. Plaintext screens remain plaintext. Inline buttons default to primary;
  unavailable catalog entries remain danger and unavailable Plans remain disabled.
- `navigation_assets` maps a legacy source path to a separate `synthetic` or
  `LhieStoreTestBot` file reference and version. Missing mappings never reuse a
  production file ID. The relay refuses media URLs/uploads, production tokens,
  unlisted chats, payment operations and pin operations. Photo and animation root
  behavior is exercised with synthetic references, not claimed as live rendering.
- `customers.py` registers only an exact numeric Telegram identity and updates its
  display profile. It never searches by email/username, links accounts or changes
  financial/auth fields. Legacy identity triggers require a transaction-local
  `pg_catalog, public, pg_temp` search path. It resets before Telegram I/O.
  The default candidate search path remains unchanged.

Private navigation is the runnable ingress/consumer scope. Group presentation is
public-safe in the presenter, but the P01 relay admits only explicitly listed
private testing chats. Other commands and Home's financial/API/support/language
callbacks keep their existing identities for their owning migration tickets;
this slice does not substitute fake implementations for those handlers.

## Explicit schema and runtime admission

Startup runs no DDL or legacy migrations. The disposable smoke explicitly applies
`schema.sql`, `ingress_schema.sql`, `assets_schema.sql` and `grants.sql` after the
repository migration oracle. Legacy models remain unmanaged.

Grants expose only the catalog/profile/metric columns needed by these queries,
plus narrow Customer registration/profile writes and candidate navigation state.
Credential bodies, password hashes, auth-version writes, balance writes and
public-schema creation remain denied. The smoke temporarily grants root-row
DELETE solely to remove its randomly scoped regression fixtures; normal grants
do not include that permission.

Readiness keeps P05's no-public-access bootstrap mode. A provisioned navigation
mode may use public-schema USAGE only when navigation metadata exists and no
public write authority beyond the narrow Customer columns is present. Database,
role, connection-budget, schema ownership and dependency checks still apply.

`python navigate.py --once` runs one bounded batch inside the admitted candidate
container. It never starts a daemon. Testing Bot navigation requires P01's exact
identity/target checks and an operator attestation with gate
`P06-approved-navigation-targets-only`. An existing text-only H0 approval cannot
enable the media/keyboard route. No token belongs in candidate settings or catalog
presentation ENV.

## Verification

Commands from the repository root:

```sh
python tools/migration_harness/harness.py build candidate
python tools/migration_harness/smoke_navigation.py
python -m unittest discover -s tests/migration_harness -p 'test_navigation_boundary.py' -v
python -m unittest discover -s tests/migration_harness -p 'test_admission.py' -v
```

Focused candidate regressions run with the pinned candidate image, no network,
a read-only root and a read-only bind of `candidate/`:

```sh
docker run --rm --network none --read-only --tmpfs /tmp:size=64m \
  --cap-drop ALL --security-opt no-new-privileges:true \
  --mount type=bind,src="$PWD/candidate",dst=/opt/candidate,readonly \
  --entrypoint python lhie-p05-candidate:1 -m unittest \
  tests.test_catalog_presentation tests.test_navigation_ingress \
  tests.test_navigation_roots tests.test_http tests.test_runtime \
  tests.test_jobs tests.test_postgres_smoke_safety -v
```

Observed locally: Python 3.13.18 / Django 5.2.18; 54 focused candidate tests,
5 relay-admission, 9 existing harness-admission and 2 interactive-target tests passed. The real
PostgreSQL smoke compared actual PHP Home/catalog/Family/detail captions and
keyboards, then exercised actual HTTP ingress through canonical screen ACK,
quantity and Back. The actual `navigate.py --once` command consumed a new
HTTP-accepted update and established Home. The smoke also exercised
duplicate/stale/foreign/late updates, same-chat ordering, column privilege denial,
exact-identity registration replay, expired/competing roots and a synchronized
two-process generation race. No SQLite locking proof is claimed.

The smoke reuses P01's resource-limited namespace and repository migration
oracle. It resets only owned disposable resources in `finally`. Its single
local result is `.local/migration-p06/verification.json`, with source hashes,
PostgreSQL version and a pointer to the existing P01 manifest. PHP reference
payloads are synthetic. Empty PHP option arrays and absent option objects are
normalized equivalently; only random callback nonce components are normalized.
Signed family payloads, captions, order, styles and callback actions are compared.

Initial verification found a legacy-trigger search-path failure, now covered by
actual registration/profile writes. Two newly written test expectations were
corrected: selecting a pagination callback must not select `family:select`, and
literal user text in a plaintext message is not interpreted as HTML. An initial
broad candidate discovery also could not run the two P03 PHP-oracle tests inside
the isolated candidate-only image because their host tools/oracle were absent.
Those external-oracle tests were not reclassified as passing; P03's existing
compatibility evidence remains separate. This is not a full-suite claim.

### Contract discrepancy

ADR-0009 says `message is not modified` must not advance the stored screen/locale
projection. The current PHP `TelegramNavigationRootService::render()` calls
`finishClaim()` in that branch and writes the projection. The candidate follows
the accepted ADR and preserves the old projection. The PostgreSQL regression
checks this explicitly; no optimistic ACK is fabricated.

## Testing Bot visual QA: completed for P06

Operator correction approved in the current conversation: the separate Testing
Bot is `@LhieStoreTestBot`, verified through `getMe` using the local ENV token.
The operator explicitly authorized this identity and the configured private admin
chat for P06 QA. The old `LhiestoreTestingBot` spelling is not an alias or fallback.

The approved local ENV token and configured admin chat were subsequently checked
with `getMe` and `getChat`: the bot identity matched and the target was private.
The private P01 candidate config now carries the explicit P06 navigation
attestation for that exact bot and one chat. No credentials enter this document.

The initial Django presenter/root/relay run sent four synthetic screens.
The operator's screenshot rejected icon parity: these fixtures had no captured
custom emoji, so successful text ACKs had not proved premium presentation.
The configured local SQLite database likewise had 91 UI slots and zero captured
IDs. This was a missing QA presentation dataset, not evidence that Unicode-only
output met acceptance.

The operator then explicitly authorized a narrowly scoped production read:
UI key/fallback/source/custom-emoji pairs and Family/Product IDs/icon pairs only.
One bounded read-only PostgreSQL transaction (3-second statement timeout,
500-ms lock timeout) read 91 UI rows (79 premium), 18 Families (15 premium) and
45 Products (4 premium). No customer/order/financial/credential data was selected
and no production mutation occurred. The private local export is
`.local/migration-p06/approved-icon-presentation.json`; no production token or
bot-specific media reference was acquired.

Testing Bot `getCustomEmojiStickers` resolved all 79 unique identifiers.
All UI slot pairs were mapped by key into the disposable dataset. One approved
Family icon pair and one Product override pair were mapped onto explicitly
synthetic catalog entries; that does not claim the synthetic product names are
production catalog parity.

Three corrected Home/catalog/Family screens were delivered through the actual
Django renderer/root/relay. Telegram's responses retained respectively 8/1/4
custom-emoji text entities and 5/5/3 custom-emoji button icons, matching all
requested counts, with no fallback. Sanitized evidence is
`.local/migration-p06/live-premium-icons.json`. The operator subsequently confirmed
the corrected premium icons visually: "Ikon sudah sesuai". Final mapped media
verification and its separate visual confirmation are recorded below.

For those initial screenshots the existing Testing Bot webhook was unchanged.
The operator subsequently explicitly requested interactive `/start` and navigation
and approved temporarily disabling that Testing Bot webhook. The manual
`tools/migration_harness/interactive_navigation.py` runner validates the exact
Testing Bot identity and P06 approval, keeps only allowlisted private actor/chat
updates, and feeds one persistent Django `interactive_navigation.py` consumer.
No Python process is created per click. Updates are not dropped.

The runner is time-bounded (30 minutes for this session), restores the saved
Testing Bot URL/settings with the locally configured webhook secret on exit,
and never changes production routing. Its ready signal and `getWebhookInfo`
confirmed polling was active; the persistent consumer had already rendered an
actual `/start` Home through the live relay. Two focused runner privacy/admission
tests passed. This manual QA command is not an automatic production worker.
The operator subsequently requested completion of the remaining P06-only QA.

The first interactive operator trial exposed a `/start` parity bug: updates were
processed but the candidate edited an old Home rather than sending at the chat
tail. PHP `TelegramMenuIntents::sendWelcome()` explicitly establishes a fresh
root. The processor now does the same for `/start`; inline Home/Back still edit
the canonical message. The PostgreSQL smoke checks a changed message ID on a
returning `/start` and stale rejection of the previous root's callbacks.

Final live verification used the existing repository welcome banner, uploaded
once to the Testing Bot as a photo, and a two-second silent H.264 animation
derived from that same image solely as a test fixture. Upload preparation was
manual and allowlisted; runtime relay uploads and arbitrary URLs remain denied.
All file references were minted by `@LhieStoreTestBot` and mapped under its own
scope in the disposable DB. No production bot file reference was reused.

The final local navigation driver exercised the actual Django processor,
presenter, PostgreSQL root and live Telegram relay through:

- `/start` -> fresh photo Home -> catalog caption edit -> Family/Plan -> Product photo;
- increment quantity -> custom quantity 3 -> Back to Plans -> Home;
- catalog Refresh -> replacement root -> old-message callback rejected without a navigation send/edit;
- a parsed caption over 1024 characters -> text replacement retaining the banner -> `/start` restores photo Home;
- English Product detail -> mapped animation Home -> animation catalog-caption edit -> final photo Home.

Telegram returned the expected photo/animation message fields and accepted the
caption edits; all 11 scenario checks passed without emoji fallback. Sanitized
results are `.local/migration-p06/live-final-navigation-media.json`. The operator
then confirmed the latest photo, animation and final Home visually: "Ya, tampil
sesuai". The operator's actual Telegram client was used (screenshot 506x673);
OS/client version was not supplied, so this is not an Android/iOS coverage claim.
Existing deterministic tests cover Unicode fallback and UTF-16 metadata, while
the final live run checks one alternate locale rather than a Cartesian matrix.
The final PostgreSQL smoke additionally rechecks the returning `/start` regression.

- [x] Required initial screen flow: PHP comparison, local E2E and live navigation driver.
- [x] Primary/danger/disabled/footer presentation: focused checks and operator screenshots/confirmation.
- [x] Separate Testing Bot photo and animation, long-caption downgrade, return to Home.
- [x] Captured premium emoji confirmed visually; Unicode fallback/UTF-16 regressions and alternate live locale.
- [x] Refresh and stale callback live checks; same-chat ordering/competing-generation PostgreSQL proof.

Final acceptance reran the focused 54 candidate tests and 5+9+2 harness/interactive
tests successfully, then the actual PostgreSQL 16.15 smoke passed on the final
runtime source, including returning `/start` and stale previous-root callbacks.
All 26 runtime source hashes in its manifest matched the current files.
The separate final live run passed 11 navigation/media scenarios, followed by
the operator's explicit visual confirmation. Testing Bot webhook URL restoration
was checked with `getWebhookInfo`; both manual polling runners had exited and no
P01 containers or owned volumes remained after final disposable cleanup.
The private approved Testing Bot configuration was restored locally.
No production deploy, production webhook change, financial operation, CI/CD,
commit, push or merge was performed by this acceptance run.

Checkout, real provider qualification, full bot parity, paired performance
measurement and human continuation decisions remain with P07/P08/P11/H1 and
later owners. No performance improvement follows from this navigation port alone.

The operator explicitly asked to keep this issue bounded after reporting slow
interactive navigation. Two diagnostic renders measured catalog/Home presentation
at 39.1/28.2 ms and Telegram relay edit time at 1938.7/3490.1 ms. A separate relay
connection probe measured 199.5 ms CA-context setup, 55.4 ms TCP connect, 644.6 ms
TLS handshake and 1933.9 ms total for `getMe`. These are local-path diagnostics,
not a PHP/Python comparison, device latency or a performance acceptance result.
Artifacts are `.local/migration-p06/live-latency-diagnostic.json` and
`relay-network-diagnostic.json`. A connection-reuse experiment was removed
without deployment. Further transport tuning and fair comparison belong to P11
(#692), not completion of P06. Correctness/parity bugs remain in P06 scope.

## Context7 references

The implementation consulted Context7's official Django 5.2 and Telegram Bot API
collections through its REST resolve-library/context endpoints:

- [Django transactions](https://docs.djangoproject.com/en/5.2/topics/db/transactions/):
  short explicit transactions; no Telegram I/O inside transaction closures.
- [Django row locking](https://docs.djangoproject.com/en/5.2/ref/models/querysets/#select-for-update):
  locking requires real transactional backend verification, not SQLite/TestCase assumptions.
- [Telegram editMessageCaption](https://core.telegram.org/bots/api#editmessagecaption):
  1024 characters after entity parsing.
- [Telegram InlineKeyboardButton](https://core.telegram.org/bots/api#inlinekeyboardbutton):
  1-64-byte callbacks, independent custom-emoji icon and primary/danger styles.

Source behavior and ownership remain in ADR-0009, ADR-0015 as amended by ADR-0024,
P00's parity inventory and canonical issue #679. No framework choice relaxes them.
