# Snapshot deferral: accepted planning decision

Canonical: #679 and HD #684, revised at the owner's request on 2026-10-30 because full snapshot acquisition was slow and costly.

| Work | Evidence needed now | Actual production evidence later |
| --- | --- | --- |
| P05 #683 | Local Django bootstrap, safe config, test environment | No HD prerequisite |
| P03 #685 | Synthetic PHP/Python vectors, repo schema baseline, test keys | HD verifies actual schema drift and complete historical key/hash coverage |
| P04/P11 #686/#692 | Identical deterministic representative data, scale assumptions, resource/background accounting | Synthetic performance is not a production latency claim |
| P08 #689 | Qualified Testing Bot/sandbox, safe targets, external smoke | Sandbox pass is not live payment qualification |
| HD #684 | Deferred until H1 continue | Complete relevant actual-data audit and actual backup restore/reconciliation before #710 |

A visual baseline is screenshots/recordings plus presentation and navigation inventory, not a customer database backup. Keep customer credentials/payment proofs/payable QR originals out of shared assets.

Neither all-history production export nor a last-seven-days export is needed to start. Date filtering alone breaks relations and cannot qualify historical financial/encryption coverage. Dataset selection never permits weakening invariants. Before production readiness, samples cannot substitute complete relevant coverage, and a backup file without demonstrated restore is insufficient.

HD remains ready-for-human and open. #685/#686/#689 no longer depend on it; #710 does. No new acquisition, SSH, storage cleanup, provider operation or CI/CD is authorized by this revision.
