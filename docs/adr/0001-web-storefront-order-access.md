# Web storefront order access

## Status

Accepted — 2026-07-10

## Decision

The Web Storefront is a standalone customer purchase surface: customers check out as guests with QRIS and do not need a Lhie Store login or OAuth provider.

For every new web order, the application shows a recovery key once, stores only its bcrypt hash, and requires the invoice number plus that key to reopen paid account credentials. A successful check creates a 30-minute server-side session grant. Credential responses use `Cache-Control: no-store, private` and `Referrer-Policy: no-referrer`; recovery attempts are rate-limited.

Customers are instructed to save the invoice, recovery key, and credentials in a private password manager or secure private screenshot. The key cannot be resent because the plaintext is not stored and this flow deliberately does not depend on email or Telegram delivery.

## Transition

Orders created before this migration have no recovery-key hash. Their existing signed invoice behavior remains temporarily intact because a replacement key cannot be generated and delivered safely without a verified customer channel. New web orders always use the recovery-key flow.

## Consequences

- OAuth is deferred: it adds account lifecycle and provider-dependency cost without helping a one-time guest checkout.
- Losing both invoice and recovery key requires a future operator-approved recovery policy; the application cannot reconstruct the key.
- The Store Bot and its Community Gate remain legacy Telegram concerns and do not gate the Web Storefront.
