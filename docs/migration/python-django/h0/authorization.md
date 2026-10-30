# Historical operator decisions and read-only observations

Recorded 2026-10-30. The entries below are historical evidence, not current
permission to acquire data or repeat cleanup.

## Current decision: synthetic experiments; HD deferred until after H1

The owner subsequently approved revising #679/#684 because the approximately
8 GB snapshot was too costly and slow. Initial experiments use synthetic or
representative data, repository schema and test keys. No full snapshot or
production key transfer is required to start #683/P03/P04/P08.

#684 remains open as HD after H1 explicitly decides to continue, and before #710.
Actual historical compatibility and backup restoration remain mandatory there,
but neither a new full snapshot nor a laptop restore is mandatory if equivalent
complete evidence can be obtained through an approved isolated method.

Earlier acquisition, recipient, retention and cleanup selections below do not
authorize new operations. Reconfirm the scope, operator, storage, capacity,
custody and retention when HD is executed. No production access is authorized
by this planning update. Testing Bot/sandbox readiness belongs to P01/P08.

## Historical user authorization

After the assistant described acquisition of required database/files/historical
keys through a protected channel and isolated local restore/sanitization, the user
answered: **"izinkan semua"**. The user then clarified:

> intinya tidak merusak production saya setuujui. kita hanya lihat aja di production seperti apa. lalu snappshoot dan lain lain yang kamu butuhkan untuk issue ini.

Scope: non-destructive production observation and acquisition of the materials
required by #684, followed by isolated local restore/sanitization. Production
business data/configuration must not be changed. No restart, deployment, CI/CD,
production webhook changes, real-money purchase or customer send is authorized.
This is permission to prepare/acquire safely, **not final H0 acceptance**.

Custody prerequisites still apply before transfer: a protected secret channel,
encrypted local storage for any raw restore, named custodians/readers and explicit
retention/deletion decisions. No raw material has been acquired. The user has not
provided Testing Bot/sandbox credentials or actual-material verification evidence.

## Read-only observations

SSH used the configured production destination with existing host verification,
BatchMode and a ten-second connection timeout. No sudo, secret file inspection,
Docker exec, SQL, process control or remote file write was performed.

At remote timestamp `2026-10-30T19:37:25+07:00`:

- Host/account identity matched the configured production target.
- Project root was the expected `github/telegram-bot` checkout under the account's
  home. Checkout revision: `d830709bb98cffacc6369847383c850ff89088f2`.
  This does not establish the contents of running images.
- Six observed containers reported healthy: app, scheduler, outbound recovery,
  PostgreSQL, Redis and web. Observed DB image tag was `postgres:16-alpine`, not
  proof of actual server patch version or schema.
- Capacity observation: 1,963 MiB total memory, 870 MiB available, 713 MiB swap
  used; filesystem 78% used with 8.5 GiB available. One Docker stats sample showed
  scheduler CPU 35.08%, database memory 472.1 MiB and app memory 203.7 MiB.
  These are point observations, not safety/capacity guarantees for a dump.

Read-only commands: hostname, date, id, uptime, project-root/revision checks,
`docker ps` formatted metadata, `free -m`, `df -hT`, and `docker stats --no-stream`
formatted CPU/memory. All three SSH invocations returned exit status zero.
No logs, ENV, Docker environment metadata, customer rows or secrets were read.

## Local custody blocker

`findmnt` places the workspace on a direct Btrfs partition;
`lsblk --json --output NAME,TYPE,FSTYPE,MOUNTPOINTS` showed no dm-crypt layer for
that partition. An encrypted raw-restore destination is **not established**.
Filesystem output alone does not rule out every possible hardware encryption
mechanism, but it cannot prove the P02 encrypted-local-storage prerequisite.
Do not restore raw customer data into the existing workspace or Docker volumes.

The existing P01/P02 local artifact parent directories have mode 0700; that is
access control, not encryption. SSH, GPG, Docker, pg_dump and pg_restore are
available locally; age is not. Tool availability is not approved key custody.
No existing private key/config contents were inspected.

Before actual transfer/restore the operator must supply or approve the protected
storage/channel, custodian and absolute retention deadlines. An encrypted archive
alone does not encrypt a plaintext restored PostgreSQL volume. No automatic
fallback to unencrypted storage, production ENV-as-config or synthetic importer
has been made. Snapshot consistency, actual schema/roles/grants, key coverage,
file reconciliation and testing targets remain unverified.

## Encrypted workspace preparation

The user selected **\"Siapkan workspace lokal terenkripsi\"** after the storage
blocker was explained. Local cryptsetup 2.8.7, filesystem tools and FUSE are
available, but no existing gocryptfs/cryfs installation was found. Local free
space was approximately 7.2 billion bytes (6.7 GiB), before any actual-material
size estimate. Capacity must include raw restore, derived copy and working space;
do not assume the whole dataset fits or allocate the remaining disk blindly.

`sudo -n cryptsetup status migration-h0-684` was a read-only privilege probe and
failed because a password is required. No encrypted image, block mapping or
mount was created. Do not use the unrelated passwordless OpenVPN permission as
a privilege bypass. LUKS setup requires the operator's local privileged terminal
and passphrase custody outside chat. Rootless encryption would require installing
and qualifying another tool and still needs an operator-owned unlock channel;
no substitute key was generated or saved beside an archive.

## Superseding user decision: schema and synthetic data only

The user selected **"Synthetic saja, tunda snapshot asli"**, then clarified that
only structure/metadata should be obtained and populated with dummy data locally,
rather than spending time acquiring actual production rows.

This supersedes the earlier actual-material acquisition and encrypted workspace
preparation permission for the current work. Do not acquire production rows,
local stock credentials, customer contacts, customer files, ENV or historical
keys. Stop encrypted workspace preparation for actual material.

Current direction: reviewed schema/metadata only, excluding role passwords and
any sensitive literals embedded in schema definitions, followed by synthetic
local fixtures. Preserve the real constraints, history shapes and domain owners;
dummy data is not evidence of historical data/key compatibility.

Actual-material qualification remains deferred and unproven before any production
migration. The original #684 acceptance criteria are not satisfied by this scope
change; no final H0 closure or downstream production authorization is implied.
The canonical parent/issue contracts need an explicit scope amendment before
schema/synthetic evidence can be used to unblock an otherwise H0-gated task.

Only public gocryptfs release/signature download preparation occurred after the
read-only observations above. No binary installation, encrypted mount, raw
snapshot/file/key acquisition or local actual-data restore was performed.

## Schema-only execution and backup decision

The user permitted skipping a new full production backup if time-consuming,
because production migration is still distant. No production-changing work is
being done. The bounded catalog probe measured 7,785,913,367 database bytes,
larger than the observed local free space; no dump/transfer duration is claimed.
Full backup is deferred, not performed. A fresh backup and proven restore remain
required before future production-changing work.

After that scope clarification, two additional authorized SSH commands used
`docker exec` solely for a bounded read-only psql catalog/size transaction and
`pg_dump --schema-only`. This was schema acquisition, separate from the earlier
host audit. No shell environment dump, application-secret lookup, business-row
SELECT, file copy, role-password export or production DDL/DML was performed.
Database connections and schema dump impose finite read/lock/I/O load; read-only
does not promise zero latency impact. The dump had a one-second lock wait limit.

[schema-only-result.json](schema-only-result.json) records the private structural
artifact, restore counts and dummy identity smoke. All 138 application tables
were empty before and after the rolled-back identity smoke. The separately
rerun P02 fixture is synthetic-only, not a population of that full schema.
Owned P01 local volumes were reset after verification. No actual production
customer/stock/payment rows or application keys have been acquired.

## Latest decision: resume original production snapshot, clean local storage first

After reviewing the cross-issue impact, the user explicitly chose to use the
original production snapshot and requested cleanup of unused Docker/cache storage
first. This supersedes the temporary schema/synthetic-only acquisition deferral.
It does not approve H0 as passed or authorize production mutation, restart,
deployment, CI/CD, webhook changes, live purchases or customer sends.

Cleanup was performed only against local Docker and local user caches:

- `docker builder prune --force`: reported 1.475 GB reclaimed.
- `docker image prune --force`: reported 0 bytes reclaimed; tagged images retained.
- `uv cache prune`: removed 39,838 files, reporting 651.9 MiB.
- `python3 -m pip cache purge`: removed 256 files, reporting 98.5 MB.
- `go clean -cache`: completed successfully.
- After explicit approval for the inventoried candidates, 155 unattached anonymous
  Docker volumes were removed without force. Their application provenance/content
  was unknown: anonymous/unattached does not prove Telegram Bot ownership or absence
  of valuable data. The user was informed of that risk before approval.
- Preserved all three named application volumes (`telegram-bot_postgres_data`,
  `telegram-bot_laravel_storage`, `telegram-bot_laravel_public`) and the two volumes
  attached to baseline containers. Final Docker volume inventory contains these five.
- Removed 64 discovered browser HTTP/code/startup cache directories only under
  the user's `.cache` tree; logical pre-removal footprint 3,994,836,992 bytes.
  Browser profile/login configuration and installed testing-browser runtimes were
  not targeted. No blanket deletion of `.cache`, repo data or backup directories.

Final local filesystem available bytes: **13,827,903,488** (about 12.9 GiB),
86% used. Docker logical reclaim figures are not additive proof of physical
filesystem reclamation, particularly on a shared/CoW filesystem.

No cleanup or mutation occurred on production. No original business snapshot,
file bundle or application keys have yet been acquired. The previously measured
database size was 7,785,913,367 bytes; it is not a dump or restored-size guarantee.
Available space does not yet prove sufficient capacity for simultaneous raw and
derived restores plus archives/index/temp headroom. Encrypted local restore
storage, separate protected secret custody, named custodians and explicit
retention remain prerequisites. No secret was generated beside an archive and
no unencrypted actual-data restore fallback was introduced.

## Approved archive recipient and retention

The user selected **"Public key SSH pribadi yang ada"**, specifically the public
recipient `~/.ssh/id_ed25519.pub`, not the deployment key, after being told that
the matching private key is required for recovery. Use pinned age with the
approved public recipient; do not copy the private key into the dataset directory,
print it, or generate an unrelated replacement identity.

The user selected **raw 3 days, derived 14 days**. The local account owner/user is
the custodian. Record absolute UTC raw expiry at acquisition; derived expiry is
14 days from actual derived creation, not from today's synthetic run. At expiry,
the custodian removes the exact raw archive/restore and protected transferred
source-material copies, then derived artifacts at their own expiry. This is not
permission to delete the user's personal SSH identity or production history.
The selected durations are an operator-owned deletion plan, not an automatic
expiry service.

Only stream the original dump from read-only pg_dump over verified SSH into local
authenticated age encryption. No plaintext dump file is permitted. A synthetic
encrypt/decrypt round trip must demonstrate that the recipient is usable first;
failure to unlock the existing private identity remains a blocker, not a reason
to create an unrecoverable archive. Successful encrypted acquisition does not
authorize raw restore, sanitizer allowlist expansion, worker startup or H0 closure.
