# Payment-stage diagnostic (#725)

**Result: inconclusive, not a paired performance or budget pass.** The current
Laravel and Django synthetic paths ran on the same host and under the same
aggregate 2 CPU / 2,048 MiB envelope, but not with the same dataset, rail,
workers, or operation boundaries. They cannot support a PHP/Django speed ratio.
All production, provider and device claims remain unmeasured. This is a fresh
run, not a relabeling or pooling of #722 or the historical two-attempt P04 run.

## Identity and execution

- Branch `migration/python-django-experiment`, base revision
  `6f26fc486056e610d0ba8896eff2617e4a19e136`, plus the probe/report changes
  identified below. PHP ran 2026-11-03 01:37:28–01:40:45 UTC; the candidate
  ran subsequently on the same host. Eight host CPUs; unrelated local `postgres`
  and `redis` containers were observed running before the diagnostics. Their
  subsequent load was not controlled or monitored; Docker, host OS and driver
  are outside the container envelope.
- PHP image `sha256:8d74804eee42814cf37ac18c5edb24c996206527e7b06b018f2eff5467ed70f7`,
  PHP CLI 8.4.27. Its verified local-dependency source-tree digest, including
  installed vendor bytes, equals the current tracked `src` plus vendor digest:
  `78b920a5f129c5364fb455a0e0010b5202c5ee7a767b4cba3ca2f3d3c21da277`.
  Composer lock SHA256 `55c3490b964f10f631720f979f13b933bbc0b4f5189fd58cb9843085a551940a`;
  asset tree `188cd1cedd77d15ce5fda67d77e10ef81a21df4b290c56c35e1646208972d1c0`.
  This is the local-development dependency image, not a production image.
- Django used the current `candidate/` tree mounted read-only over dependency
  image `sha256:5e2c9e4e801e0fa6baf26331a9c600752b6d4ccc6cf9ec3d611dbe4eaca8f652`:
  Python 3.13.18, Django 5.2.18, psycopg 3.3.4; requirements lock SHA256
  `9e94b49d8ac52a09056176130a0f6bcb7169d41c040fb65a26ca1d544f70bbc9`.
  Candidate probe SHA256 `6f6e6ec743a040d784fb3756290784d6234a559bc0f671253c7f15fff8f0eed3`.
  The mounted working tree included the #725 probe changes; it was not a
  built release image.
- Both used PostgreSQL 16.15 and Redis 7 Alpine in isolated disposable P01
  namespaces. PHP verified `fsync`, `full_page_writes`, `synchronous_commit`
  on, `max_connections=30`, `shared_buffers=128 MiB`, UTC. The candidate used
  the same P01 PostgreSQL command and image, not a separately sampled settings
  snapshot. Boundary/database/Redis/app limits were respectively
  0.1/0.5/0.15/1.25 CPU and 64/640/128/1,216 MiB, with no container swap.
  Candidate ran the bounded diagnostic container instead of the app container;
  neither run establishes representative resource headroom.
- PHP seed `p04-repository-synthetic-v1`, small, seed 686, logical SHA256
  `fd5a8a4c88736489f99da32d5c25bc9c5112ef861b5ef9f215a1366d81c0419a`:
  4 categories, 12 families, 36 products, 20 customers, 60 Orders and Payments,
  1,840 stock items. The independent cells used fresh clones. Candidate used
  the separate repository navigation and checkout synthetic fixtures, SHA256
  `158049408b78b03f1c5ef65b9b65d27d120aaf31652b9087985d1602aa30e6fc`
  and `9501a34c9a0d8987b0a4095bf9ff94920a4290bc436a25d58a6b45e60c1b0030`.
  These dataset identities are **not equal**.
- Source hashes: PHP probe
  `55a36d21fb121d9011960a68e705cba3c685f9d74a9a1fb06eb517009fa08fdc`;
  report `6dcedf42521a13df9f612f91e5b2bf297b715337d382d5820c68ee0ae782ebd1`;
  runner `21214f81ff69cf1c87008c3cd730bf74cf441d83453a47a1dfe799d527ab48dd`;
  P04 protocol `a32b4c893c00ed06e592f85847d64871b0444e33ad0fd0f930ea726514ab6dd3`;
  dataset contract `682fb320bae7ffff1cf75053836712dae9ba1e5baef6fd2be3837a69b53a2956`.

The executed commands were `python3 .local/issue725-php-driver.py` and
`python3 .local/issue725-django-driver.py`. The exact sanitized driver contents
are retained as [PHP driver](php-driver.py.txt) and [Django driver](django-driver.py.txt).
They call the existing P04 runner and P01/P03/P07/P09 owners; they are run
records, not an additional benchmark framework. Their fixed output paths must
be changed to new `.local` directories before reuse. Each driver generated a
unique project prefix, refused another active disposable stack and reset only
its own volumes. No existing stack or volume was reset. The Django driver
suppressed startup redaction only inside its isolated synthetic diagnostic so
failures could be diagnosed; its private stderr is not retained here.

[Sanitized per-attempt samples](samples.json) SHA256
`cb8eb735c2877f26ad149808bb7c2b8ec2d3da02720f3701eae75620547681e9`;
driver SHA256 values are `1b7e37a9e718b7122b9421812054bf6aa6aa7c758bcb0ebc7acd24583581b785`
(PHP) and `ecdbca139b98bc48984bf95e9d43c388c60a89e0f18c3387995119d86dcc36f5`
(Django). Samples omit IDs, payloads, credentials and absolute timestamps.

## Boundaries, transports and outcomes

All synthetic elapsed times use the same host monotonic clock, not wall-clock
subtraction across hosts. PHP `ingress` is the local HTTP-kernel submission,
not a physical button press. Its new `checkout_committed` event is an
`afterCommit` callback for the creating Order: it is a post-commit observation,
not the database's exact commit instant. Instructions are the successful fake
Telegram response of the instruction job. PHP's fake paid trigger is marked
immediately before publishing the synthetic state; it is not a trusted provider
confirmation or a customer payment action. The probe explicitly dispatches a
verification job: it does not measure callback delivery or a provider polling
cadence. Nine PHP workers use the existing current-profile sleeps; the payment,
receipt and outbound workers sleep one second when idle. No background jobs were
injected; end-of-cell queue backlog and failed jobs were both zero.

Django's checkout is intentionally blocked while the existing concurrency and
revalidation checks run, so its submission-to-commit and instruction ACK are
`not_measured`. Its payment timing starts at an explicit payment-worker drain;
status is returned by an in-process fake, not an actual provider callback or
polling schedule. The outer settlement `on_commit` callback is a post-commit
observation. The receipt is materialized and delivered synchronously by the
probe; observation-to-ACK/completion includes probe/admin SQL and is only a
diagnostic duration of that instrumented path, not an upper bound on production
or another worker path. Credential ACK is taken only from an effect whose
`contains_credentials` metadata is true; unknown metadata remains unknown.
Receipt completion here means the credential-effect group, not a timed Order
completion transition.

PHP uses a fake KlikQRIS adapter and in-process `Http::fake`; Django uses a
fake Sakurupiah-shaped provider and fake receipt transport. No provider or
Telegram network time is measured, not zero. PHP is an in-process CLI
HTTP-kernel cohort, not Nginx/FPM; Django bypasses the normal supervised
receipt worker. A backend fake ACK is not device-visible delivery. A trusted
provider-confirmed timestamp, cross-system clock relationship, real callback
latency, provider/network durations and provider timing SLA are all unknown.
The application's [20–24 s Sakurupiah active-check cadence](../../../../../runbook-sakurupiah-payment.md#checkout-monitoring-policy-678)
is an application scheduling policy, not a provider timing limit; it was not
exercised by these probes. Django's configured five-second worker sweep is also
not the measured explicit check.

PHP checkout and paid-delivery each had 2 attempts, all successful, zero
errors/timeouts/unknowns and zero retries. Django's final corrected diagnostic
had one successful QRIS flow, with a fake status failure retained as pending,
a simulated lost worker marked `unknown`, one provider create despite replay,
one paid settlement and one receipt group. Six forbidden runtime writes were
denied. These fault checks are not failed or ambiguous timed payment samples;
timed ambiguous delivery was **not exercised**. Earlier Django setup/diagnostic
runs failed first at an obsolete banner assertion and then at an exact render
outcome assertion after the QR-photo change; they are not included in the final
sample. A prior successful diagnostic before correcting first-credential ACK
attribution is also excluded. No retry was added to obtain a performance pass.

## Observations and provisional budgets

Units are milliseconds. PHP entries are the two individual observed values,
in attempt order; Django entries are one observation. With one or two samples,
p50/p95 are not statistically meaningful and are not reported as a budget
verdict. Missing stages are not zero. The [owner's H1 decision](https://github.com/Alhiefikri/telegram-bot/issues/693#issuecomment-6592853723)
recorded the provisional budgets; their provider assumptions remain unvalidated.

| Stage and provisional p95 budget | PHP checkout cell | PHP paid-delivery cell | Django synthetic diagnostic | Verdict |
| --- | --- | --- | --- | --- |
| Submission → checkout committed: 5,000 | 340.318, 438.943 | 859.992, 225.309 | not_measured: deliberately blocked checkout | inconclusive |
| Submission → instructions backend ACK: 30,000 | 1,982.403, 1,202.175 | 2,240.073, 691.674 | not_measured: deliberately blocked checkout | inconclusive |
| Instructions visible on device: 30,000 | not_measured | not_measured | not_measured | inconclusive; fake ACK is not visibility |
| Trusted provider confirmation → app observation: 120,000 | not_measured | not_measured; fake-trigger → observation 299.842, 343.196 | not_measured; explicit drain → fake status return 11.605 | inconclusive; no trusted provider timestamp |
| App observation → first credential ACK: 30,000 | not_measured | 1,485.226, 525.938 | 669.397, includes probe/admin work and synchronous delivery | inconclusive |
| App observation → receipt-group completion: 60,000 | not_measured | 1,489.040, 528.233 | 684.992, includes probe/admin work and synchronous delivery | inconclusive |
| App observation → Order completion: 60,000 | not_measured | not_measured | not_measured; completed status was checked, not timed | inconclusive |

Additional diagnostic components are retained in the samples: PHP paid
observation → paid commit 337.270/129.483; receipt enqueue → pickup
159.472/4.012; receipt pickup → first credential ACK 986.714/391.970.
Django explicit drain → post-commit callback was 48.680; materialize was
293.674; direct delivery start → credential ACK 178.745. These components
have different start points and must not be added or compared across runtimes.

## Ranked follow-up candidates

1. **PHP commit → instruction ACK:** 1,642.086/763.232 in checkout and
   1,380.081/466.364 in paid-delivery were the largest observed intra-attempt
   checkout intervals. This includes instruction queueing, rendering and fake
   transport; the measurements do not isolate their shares. Instrument those
   existing boundaries before optimizing; do not assume provider latency.
2. **PHP receipt pickup → credential ACK:** 986.714/391.970 in the two paid
   attempts, versus enqueue → pickup 159.472/4.012 and observation → paid
   commit 337.270/129.483. This is a small synthetic diagnostic, not proof of a
   production bottleneck or a stable ranking under load. Profile receipt render,
   DB and transport separately without weakening effect-ledger safety.
3. **Django materialization and delivery require a comparable worker-path
   trace:** the single synchronous materialization was 293.674 and the
   observation → credential ACK diagnostic was 669.397, with harness SQL in
   between. Neither establishes worker queue delay or a PHP/Django comparison.

The largest unmeasured risks are real provider/network time, callback versus
polling, representative backlog and device presentation. Do not rank those as
zero or invent a provider SLA. The previously authorized, separate [Testing Bot
sandbox observations](../../verification.md#testing-bot-checkout-and-synthetic-receipt)
showed one synthetic receipt after an explicit status/history check; automatic
callback and timing remain unproven. No sandbox invoice, webhook change, real
payment, customer data, deployment, CI/CD or paid service was used in #725.

## Verification and references

- `python3 -m unittest discover -s tests/migration_harness -p 'test_benchmark*.py'`: 39 passed.
- Candidate `tests.test_navigation_checkout` and `tests.test_navigation_roots`
  in the existing dependency image with current source mounted read-only: 30 passed.
- `php -l tools/migration_benchmark/probe.php` and Python compilation of the
  report/probe passed. The synthetic Docker smokes above exercised the changed
  boundaries and retained the measured attempts.
- Context7: [Django 5.2 transaction callbacks](https://docs.djangoproject.com/en/5.2/topics/db/transactions/)
  run after the outermost successful commit and are discarded on rollback;
  [Python 3.13 monotonic clock](https://docs.python.org/3.13/library/time.html#time.monotonic)
  supports elapsed differences and the same clock across processes. Neither
  establishes a trusted provider clock.
