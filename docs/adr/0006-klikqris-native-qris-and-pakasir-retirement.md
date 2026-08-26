# KlikQRIS Native QRIS evolution and Pakasir retirement

## Status

Accepted — 2026-08-09

The Payment Provider Switch remains owner-controlled: there is no scheduled provider change and no automatic fallback from KlikQRIS to Manual. The existing KlikQRIS Native QRIS module will be deepened directly; do not introduce a provider-neutral Native QRIS module or move Pakasir behind a new seam. If a KlikQRIS create outcome is uncertain, the same Payment is recovered and never replaced with a Manual payment, preventing duplicate payment instructions. Pakasir remains a legacy payment path only until every existing Pakasir Payment is terminal and the owner explicitly approves its removal.

## Consequences

- New KlikQRIS work keeps create, recovery, persistence, payability, and safe outcomes local to the existing KlikQRIS module.
- Web Storefront and Store Bot receive safe outcomes; Telegram owner alerting is an adapter concern after retry requires action.
- Pakasir is not expanded or migrated into the KlikQRIS module. Its reconciliation and expiry path remain only for existing Payments until the retirement criterion is met.

The retirement gate is satisfied only when there are zero non-terminal
Pakasir Payments, zero dispatchable or retryable Pakasir cancellation attempts,
zero serialized legacy Pakasir queue payloads, and an explicit approval recorded
by a current owner. The expand cutover locks the provider-setting rows and
retains `pakasir_checkout_flow`; its destructive column removal is a separate
gated contract migration.
