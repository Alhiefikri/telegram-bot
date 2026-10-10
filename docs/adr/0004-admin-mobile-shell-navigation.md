# Admin mobile shell navigation

## Status

Accepted — 2026-08-03

The admin mobile shell exposes four primary destinations in this order: Ringkasan, Pesanan, Produk, and Lainnya. Lainnya is a transient utility surface containing Siaran, Store Channel, and Tambah Produk. The mobile navigation remains fixed, but its rendered height plus the device safe-area is reserved in the scrollable content; dialogs and the utility surface layer above it. Non-Ringkasan tabs use a compact sticky top bar with the active section title and store status, while Ringkasan retains the full hero. Desktop keeps all six destinations/actions directly visible in one row.

## Context

The previous shell placed six mobile controls in a row wider than narrow viewports, clipped the last action, and allowed the fixed navigation to cover broadcast, Store Channel, and product-modal controls. Repeating the full hero on every tab also delayed access to the task an operator had selected. Desktop navigation used a four-column grid for six controls, producing an orphaned second row.

The shell is an operational interface for admins, not a customer-facing marketing surface. The primary trade-off is one extra tap for utility actions on mobile in exchange for reliable touch targets, unclipped navigation, and clear priority for order and catalog work.

## Consequences

- Ringkasan, Pesanan, and Produk remain directly reachable from the mobile shell; Siaran, Store Channel, and Tambah Produk move behind one Lainnya action.
- Lainnya must expose dialog semantics, contain focus, close on Escape/outside selection, close after a destination is chosen, and restore focus to its trigger.
- Fixed navigation can remain persistent without occluding content because the shell reserves its actual height and safe-area inset and uses scroll padding for focused controls.
- Product, order, broadcast, and Store Channel tabs become task-first: the full hero is removed and replaced by a compact sticky title/status bar.
- Store-link actions stay in Ringkasan rather than being duplicated in every internal tab.
- Desktop does not need the mobile utility indirection; all six actions remain visible in one row.
- Payment, Binance, deadline, WhatsApp, BSC receivers, and IDR/USDT funding configuration now live in a separate Pengaturan destination. Its payment, balance, and diagnostics sections load independently. Ringkasan contains daily sales, a currency-separated 7/30-day chart, actionable warnings, and recent orders; it contains no configuration forms. The current sidebar/drawer shell replaces the original bottom-navigation layout described above.
- The behavior is verified at a minimum width of 320 CSS pixels and representative 390, 768, and 1440 pixel viewports through real-browser tests.
