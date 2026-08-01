# Admin Operational Summary Design

## Goal

Make the first content in the admin Ringkasan an operational work queue. Operators should immediately see work that needs action and open the correctly filtered destination from every count.

## Operational Queues

The `Perlu tindakan` section contains four linked counts:

1. `Pesanan diproses`: orders whose order status is `processing`. Opens the Pesanan tab with `sales_filter=processing`.
2. `Pembayaran perlu perhatian`: unique orders that either have a submitted manual payment proof while payment remains pending, or are cancelled/expired despite being paid and therefore need refund/support. Opens the Pesanan tab with `sales_filter=payment_attention`.
3. `Reservasi stok`: draft or pending-payment orders that may still hold stock. Opens the Pesanan tab with `sales_filter=waiting`.
4. `Stok rendah`: active products whose available stock (`stock - reserved_stock`, bounded at zero by the existing product model behavior) is at most three. Opens the Produk tab with `products_filter=low_stock`.

Counts and destination queries use the same predicates so a linked count cannot disagree with its resulting list.

## Data And Filtering

`AdminDashboardData` accepts and forwards a normalized product filter in addition to the existing sales filter and product page. `AdminDashboardSalesData` gains the `payment_attention` filter and applies its payment-aware predicate before pagination. `AdminDashboardProductsData` gains the `low_stock` filter and applies it before pagination.

`AdminDashboardStatsData` receives the operational counts and exposes linked queue cards as a dedicated view-model collection. The existing generic Ringkasan stat cards are no longer rendered in the tab because their secondary metrics duplicate the header.

Invalid filter values continue to fall back to each tab's unfiltered list. Pagination retains active filters through query strings.

## Layout

Within the Ringkasan tab, content order is:

1. `Perlu tindakan` heading and four linked queue cards.
2. Store-link utilities.
3. Seven-day earnings.
4. A collapsed `Pengaturan pembayaran & dukungan` disclosure containing provider mode, payment TTL, and support contact controls.

The existing header remains the place for secondary summary metrics. The operational cards use anchor semantics and retain the established admin visual language and minimum touch-target sizing.

## Testing

Feature tests create records for every queue type and prove:

- queue counts use the defined predicates;
- each queue URL contains the correct tab and filter;
- payment-attention and low-stock destinations only render matching records;
- invalid filters safely return the unfiltered view;
- Ringkasan no longer renders the duplicated generic metric section before operational work.

Playwright runs the same assertions at desktop and Telegram Mini App widths. It verifies that `Perlu tindakan` appears before store utilities, earnings, and settings, then follows each operational link and checks the active destination filter and matching content.

## Scope

This change does not introduce a new settings tab, credential-level reservation page, bulk actions, or client-side filtering. Existing unrelated Camouproxy worktree changes remain untouched.
