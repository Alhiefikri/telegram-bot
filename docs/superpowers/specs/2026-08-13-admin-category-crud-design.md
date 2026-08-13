# Admin Category CRUD Design

## Context

The admin Products tab currently provides a compact form that can only create categories. The category model and policy already support update, soft delete, visibility, ordering, and product relationships, but the dashboard has no interface or HTTP flow for managing existing categories.

Products use a nullable category relationship with `nullOnDelete()`. Therefore, deleting a category can safely leave its products active as **Uncategorized Products** instead of deleting or deactivating them.

## Goal

Authorized Owner/Admin users can create, inspect, edit, hide, order, and delete Product Categories from one discoverable management interface in the Products tab.

## User Experience

1. Replace the always-visible quick-create card with a **Kelola Kategori** button near **Tambah Produk**.
2. The button opens a category-management modal.
3. The modal contains:
   - a compact create form;
   - a list of all non-deleted categories;
   - each category's name, description, customer-visibility status, display order, and product count;
   - **Edit** and **Hapus** actions.
4. Editing exposes name, optional description, **Tampil/Disembunyikan**, and numeric display order.
5. The slug is never directly editable. The system continues to own slug generation and uniqueness.
6. Deletion requires an explicit confirmation explaining the affected product count and that those products will become **Tanpa kategori** while remaining active.
7. Successful or invalid submissions return to the Products tab and reopen or focus the relevant category-management state with clear feedback.
8. Users without product-mutation permission do not see the management button or mutation controls.

## Behavior

### Create

- Require a trimmed name of at most 120 characters.
- Accept an optional trimmed description of at most 500 characters.
- Default to visible with display order `0`.
- Generate a unique slug using the existing collision behavior, including soft-deleted categories.
- Record `category.created` in the audit log.

### Read

- Load all non-deleted categories for the management list, including hidden and empty categories.
- Order the management list by display order, then name, then ID.
- Include a product count so the deletion consequence is visible before confirmation.
- Keep product-form category choices sourced from non-deleted categories. Hidden categories remain assignable because visibility controls customer browsing rather than administrative validity.

### Update

- Allow changing name, description, visibility, and display order.
- Keep the existing slug stable when the name changes. This avoids changing customer-facing filter identifiers merely because display copy was corrected.
- Validate display order as an integer.
- Persist and audit only actual changes as `category.updated`, with old and new values.
- Category visibility affects category discovery in the Web Storefront and Store Bot through the existing `is_active` filtering behavior. It does not deactivate assigned products.

### Delete

- Authorize through the existing category policy.
- Soft-delete the category.
- In the same database transaction, set `category_id` to `null` for every product assigned to it before deleting the category. This makes the intended **Uncategorized Product** transition explicit and consistent across database engines.
- Do not change product visibility, stock, reservations, or sale availability.
- Record `category.deleted` with the category snapshot and affected product count.
- The deleted category disappears from management and product selection. Its former products display as **Tanpa kategori** in admin and customer catalog behavior.

## HTTP and Application Shape

- Retain `POST /admin/categories` for creation.
- Add `PATCH /admin/categories/{category}` for update.
- Add `DELETE /admin/categories/{category}` for soft deletion.
- Use dedicated form requests for update and delete authorization/validation.
- Keep controllers thin. Put delete reassignment plus audit logging behind one transactional category-management service so the mutation has one canonical boundary.
- Extend the admin dashboard data/state objects with the category-management list and modal/error state rather than querying from the Blade view.
- Reuse existing admin dashboard components and Alpine modal conventions.

## Testing

### Feature tests

- Authorized Owner/Admin can view the management entry point and category list.
- Read-only roles cannot see controls and receive authorization failures for create, update, and delete requests.
- Creation retains unique-slug behavior and audit logging.
- Update changes only allowed fields, keeps the slug stable, handles validation in a dedicated error bag, and writes the expected audit diff.
- Hiding a category removes it from Web Storefront and Store Bot category discovery without deactivating its products.
- Delete requires an existing non-deleted category, soft-deletes it, nulls assigned products, preserves product active/stock state, and records affected product count in audit data.
- Category options and admin labels correctly show former products as **Tanpa kategori**.
- Validation redirects reopen the category-management modal and focus the relevant form.

### Browser coverage

- Desktop and mobile users can open and close the modal.
- Create, edit, status, ordering, and delete-confirmation controls remain usable at responsive widths.
- The delete confirmation clearly communicates product reassignment before submission.

### Regression checks

- Run focused admin product/category feature tests.
- Run storefront catalog and Telegram catalog tests because category visibility and deletion affect both channels.
- Run the admin dashboard Playwright suite and frontend build.

## Non-goals

- Restoring deleted categories through the dashboard.
- Permanently deleting categories.
- Bulk category operations.
- Drag-and-drop ordering. Ordering uses an explicit numeric field.
- Category banner-image management.
- Automatically deactivating or deleting products when their category is hidden or deleted.
- Allowing administrators to edit category slugs.

## Documentation Decision

No ADR is required. The design uses existing schema capabilities and conventional CRUD behavior, is straightforward to reverse, and does not introduce a surprising architectural commitment. The canonical domain terms are recorded in `CONTEXT.md`.
