# Admin Siaran Navigation Restoration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore discoverable desktop/mobile access to the existing admin Siaran tab and prevent regressions in direct URL and click navigation.

**Architecture:** Keep the current Blade plus Alpine tab architecture. Add minimal controls to the two hardcoded navigation partials and exercise the real rendered dashboard through feature and Playwright tests.

**Tech Stack:** Laravel Blade, Alpine.js, PHPUnit, Playwright.

## Global Constraints

- Keep `broadcast` as the existing internal tab ID.
- Use `Siaran` as the visible navigation label.
- Do not change routes, authorization, queueing, or broadcast delivery.
- Do not refactor unrelated navigation tabs.

---

### Task 1: Add regression coverage

**Files:**
- Modify: `src/tests/Feature/AdminDashboardAccessTest.php`
- Modify: `src/tests/Browser/admin-dashboard-responsive.spec.js`

**Interfaces:**
- Consumes: Existing `/admin?tab=broadcast` route, `adminDashboard` Alpine component, desktop/mobile navigation landmarks.
- Produces: Assertions requiring a visible Siaran control, form/status content, direct URL activation, and desktop/mobile click behavior.

- [ ] **Step 1: Write the failing feature test**

Add an owner-authenticated request to `/admin?tab=broadcast` that asserts the response contains the broadcast active state, two Siaran navigation labels, `admin-section-broadcast`, `broadcast-message`, and `Status Siaran`.

- [ ] **Step 2: Run the feature test to verify RED**

Run: `cd src && php artisan test tests/Feature/AdminDashboardAccessTest.php --filter=broadcast_tab`
Expected: FAIL because the rendered navigation does not contain two Siaran controls.

- [ ] **Step 3: Extend Playwright navigation coverage**

In `expectClickedDashboardControlsToWork`, click the exact `Siaran` button, assert the `Siaran` heading and `Status Siaran` are visible, and assert the clicked button has `bottom-nav-active`. Add a loop for desktop and mobile viewports that opens `/admin?tab=broadcast` after login and checks the same content and active state.

- [ ] **Step 4: Run Playwright to verify RED**

Run: `cd src && npm run test:browser:admin -- --grep "Siaran|switches admin dashboard tabs"`
Expected: FAIL because no Siaran navigation button exists.

### Task 2: Restore navigation controls

**Files:**
- Modify: `src/resources/views/admin/dashboard/desktop-navigation.blade.php`
- Modify: `src/resources/views/admin/dashboard/mobile-navigation.blade.php`
- Modify: `src/resources/views/admin/dashboard/broadcast-tab.blade.php`

**Interfaces:**
- Consumes: Alpine `activeTab` state and existing `bottom-nav-active` styling.
- Produces: A `type="button"` labeled `Siaran` in each navigation, setting `activeTab = 'broadcast'` and reflecting active state.

- [ ] **Step 1: Add the desktop control**

Add a control matching adjacent desktop tab buttons with a broadcast/megaphone icon, `x-on:click="activeTab = 'broadcast'"`, and `x-bind:class="activeTab === 'broadcast' ? 'bottom-nav-active' : ''"`.

- [ ] **Step 2: Add the mobile control**

Add the equivalent mobile button with the exact accessible name `Siaran`, `x-on:click="activeTab = 'broadcast'"`, and the matching active class expression.

- [ ] **Step 3: Correct the stale view comment**

Remove the statement that the Broadcast tab is hidden from bottom navigation because it is no longer true.

- [ ] **Step 4: Run focused tests to verify GREEN**

Run: `cd src && php artisan test tests/Feature/AdminDashboardAccessTest.php --filter=broadcast_tab`
Expected: PASS.

Run: `cd src && npm run test:browser:admin -- --grep "Siaran|switches admin dashboard tabs"`
Expected: PASS on desktop and mobile cases.

### Task 3: Verify and commit

**Files:**
- Verify all files from Tasks 1 and 2.

**Interfaces:**
- Consumes: Completed regression fix.
- Produces: A verified commit limited to issue #175.

- [ ] **Step 1: Run relevant backend tests**

Run: `cd src && php artisan test tests/Feature/AdminDashboardAccessTest.php tests/Feature/AdminTelegramBroadcastQueueTest.php`
Expected: All tests pass.

- [ ] **Step 2: Run the complete admin browser suite**

Run: `cd src && npm run test:browser:admin`
Expected: Build succeeds and all admin Playwright tests pass.

- [ ] **Step 3: Review the scoped diff**

Run: `git diff --check && git diff -- src/tests/Feature/AdminDashboardAccessTest.php src/tests/Browser/admin-dashboard-responsive.spec.js src/resources/views/admin/dashboard/desktop-navigation.blade.php src/resources/views/admin/dashboard/mobile-navigation.blade.php src/resources/views/admin/dashboard/broadcast-tab.blade.php`
Expected: No whitespace errors and only issue #175 changes.

- [ ] **Step 4: Commit**

```bash
git add src/tests/Feature/AdminDashboardAccessTest.php src/tests/Browser/admin-dashboard-responsive.spec.js src/resources/views/admin/dashboard/desktop-navigation.blade.php src/resources/views/admin/dashboard/mobile-navigation.blade.php src/resources/views/admin/dashboard/broadcast-tab.blade.php docs/superpowers/plans/2026-07-31-admin-broadcast-navigation.md
git commit -m "fix: restore admin broadcast navigation"
```
