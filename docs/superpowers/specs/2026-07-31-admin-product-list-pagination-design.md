# Admin Product List Pagination Design

## Problem

The admin dashboard currently loads only the five newest products with `latest()->limit(5)`. Older products remain in the database but disappear from the management interface, preventing admins from editing stock, visibility, credentials, or product details.

## Decision

Replace the fixed five-product query with server-side pagination of 20 products per page, ordered newest first. Keep the existing product cards and management actions unchanged.

## User experience

- The first page shows the 20 newest products.
- Older products remain reachable through simple Previous and Next controls plus page numbers.
- Active, paused, available, and sold-out products are all included.
- Existing product actions and modal payloads continue to work.
- Pagination links preserve unrelated dashboard query parameters where Laravel supports appending the current query string.

## Architecture and data flow

`AdminDashboardProductsData` will accept the current products-page query parameter, paginate the product query, transform the current page collection into the existing card payload, and return compact pagination metadata. The dashboard view will render the existing grid and a minimal navigation block only when multiple pages exist.

The stock-item summary and credential loading remain scoped to the product IDs on the current page, preserving bounded query and payload size.

## Error and edge handling

- Invalid or excessive page numbers resolve to an empty page with navigation back toward valid pages through paginator metadata.
- A catalog with 20 or fewer products shows no pagination controls.
- Soft-deleted products remain excluded according to the existing Product model behavior.
- Products with zero stock remain present because pagination does not apply availability filters.

## Testing

Feature tests will create more than 20 products and verify:

1. The newest 20 appear on page one.
2. Older products appear on page two.
3. Sold-out and paused products remain visible.
4. Existing management payloads and actions remain available.
5. Pagination controls are absent for a single-page catalog and present for multiple pages.

## Non-goals

This change does not alter storefront visibility, inventory calculations, ordering, deletion behavior, database schema, or checkout flows.
