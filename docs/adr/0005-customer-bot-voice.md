# Customer-facing bot voice

## Status

Accepted — 2026-08-07

Store Bot messages sent directly to customers (menu, cart, catalog, order, checkout, support) follow one voice: warm, casual Bahasa Indonesia with light particles ("nih", "ya", "biar sat-set"), and warm-but-professional English — not translated 1:1 into the same slang level. Emoji are used one per line as an info-category label (never doubled/sandwiched on the same line, e.g. not `"🎉 X 🎉"`). The `━━━━━━━━━━━━━━` separator is reserved for long messages (welcome, order history, order detail) and dropped from short ones (cart confirmation, brief notices). Error/limit messages keep the same warm, human tone but drop celebratory emoji and casual particles, and must describe impact and next action only — never internal operational detail (config names, system terms, "the admin needs to check X").

## Consequences

- Admin-facing messages (stats, broadcast, order notifications to admin) and public Sales Proof Posts are explicitly out of scope — different audience, different purpose, may keep a different tone.
- New or edited customer-facing bot copy should follow this voice; deviations are a bug, not a style choice.
