# Binance Payment Verification workflow

## Status

Accepted — 2026-08-02

The Store Bot, Web Storefront, queue, and console use one Binance Payment Verification workflow behind channel adapters. The workflow owns Binance-specific Payment state, submission/version rules, amount-unique and Order ID matching policies, grace and retry decisions, stale-submission protection, and the single-claim transaction; it returns typed outcomes rather than channel messages or raw status arrays. OrderLifecycle remains responsible for order, inventory, credential, and Sales Proof Post transitions, and manual-review notification remains an adapter decision.

## Consequences

- Amount-unique and Order ID payments share one verification workflow but keep separate matching policies.
- A successful claim persists payment evidence, invokes OrderLifecycle, and records its PaymentEvent atomically; queue notifications run only after commit.
- The first valid claim wins: repeated checks return explicit AlreadyPaid, StaleSubmission, or TransactionAlreadyUsed outcomes without duplicate lifecycle or notification work.
- Submission state remains durable when a provider or queue fails; the workflow controls retry eligibility, and reconciliation discovers committed Payments whose queue work was lost. Configuration failures are non-retryable.
- An exact match after the grace deadline, or a final mismatch, never auto-claims the Order. The typed outcome carries the evidence to the existing manual-review path.
- Existing Binance queue job names remain thin transport adapters so serialized work can drain during migration.
- Telegram prompt idempotency remains governed by ADR-0002; the Web Storefront remains a standalone guest surface governed by ADR-0001 and continues using Recovery Key access.
- The migration is a staged clean cutover: retain observable behavior and adapter coverage, replace implementation-coupled tests, then delete raw cross-channel protocols and duplicated verification paths.

## BSC extension — 2026-09-22

New `USDT_BEP20` Payments use an amount-unique snapshot instead of requiring customer-submitted transaction hashes. Checkout allocates the first free micro-USDT amount while the payment-setting lock is held. Verification uses bounded `eth_getLogs` discovery only to find a candidate transaction, then reuses the authoritative receipt, block-fact, confirmation, idempotent claim, and manual-review boundaries. Historical BSC Payments with `verification_mode=transaction_hash` retain the targeted hash path.
