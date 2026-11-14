import assert from "node:assert/strict";
import test from "node:test";
import adminDashboard from "../../resources/js/admin-dashboard.js";
import { workspaceStock } from "../../resources/js/product-workspace.js";

function setup(t) {
  const originalFetch = global.fetch;
  t.after(() => { global.fetch = originalFetch; });
  const dashboard = adminDashboard({});
  dashboard.manageStockModalOpen = true;
  dashboard.applyManageStockPayload({
    id: 1,
    stockItems: [
      { id: 1, status: "TERSEDIA", secret: "ready", editable: true },
      { id: 30, status: "PAID", secret: "newest" },
      { id: 40, status: "PAID", secret: "older" },
    ],
    paidPagination: { previousUrl: null, nextUrl: "/next" },
  });
  return dashboard;
}

test("PAID pages replace history in server date order without discarding ready edits or selection", async (t) => {
  const dashboard = setup(t);
  assert.deepEqual(dashboard.manageStockGroups().find(g => g.key === "paid").items.map(i => i.id), [30, 40]);
  const ready = dashboard.manageStockItems.find(i => i.id === 1);
  ready.secret = "unsaved edit";
  ready.editing = true;
  dashboard.manageStockSelectedIds = [1];
  global.fetch = async () => ({
    ok: true,
    json: async () => ({
      stockItems: [{ id: 20, status: "PAID" }, { id: 25, status: "PAID" }],
      paidPagination: { previousUrl: "/previous", nextUrl: null },
    }),
  });
  await dashboard.loadManageStockPaidPage("/next");
  assert.equal(dashboard.manageStockItems[0], ready);
  assert.equal(ready.secret, "unsaved edit");
  assert.equal(ready.editing, true);
  assert.deepEqual(dashboard.manageStockSelectedIds, [1]);
  assert.deepEqual(dashboard.manageStockItems.filter(i => i.groupKey === "paid").map(i => i.id), [20, 25]);
  assert.equal(dashboard.manageStockPaidPagination.previousUrl, "/previous");
  assert.equal(dashboard.manageStockPaidPagination.nextUrl, null);
});

test("failed page loads retain current accounts and navigation for retry", async (t) => {
  const dashboard = setup(t);
  const items = dashboard.manageStockItems;
  global.fetch = async () => { throw new Error("offline"); };
  await dashboard.loadManageStockPaidPage("/next");
  assert.equal(dashboard.manageStockItems, items);
  assert.equal(dashboard.manageStockPaidPagination.nextUrl, "/next");
  assert.equal(dashboard.manageStockPaidError, "offline");
  assert.equal(dashboard.manageStockPaidLoading, false);
});

test("closing modal ignores late PAID response and prevents duplicate page requests", async (t) => {
  const dashboard = setup(t);
  let release;
  let calls = 0;
  global.fetch = async () => {
    calls++;
    return new Promise(resolve => { release = resolve; });
  };
  const pending = dashboard.loadManageStockPaidPage("/next");
  await dashboard.loadManageStockPaidPage("/next");
  assert.equal(calls, 1);
  dashboard.closeManageStockModal(false);
  release({ ok: true, json: async () => ({
    stockItems: [{ id: 2, status: "PAID" }],
    paidPagination: { previousUrl: "/previous", nextUrl: null },
  }) });
  await pending;
  assert.deepEqual(dashboard.manageStockItems, []);
  assert.deepEqual(dashboard.manageStockPaidPagination, { previousUrl: null, nextUrl: null });
  assert.equal(dashboard.manageStockPaidLoading, false);
});

test("destroyed dashboard and workspace discard late PAID credentials", async (t) => {
  const originalFetch = global.fetch;
  t.after(() => { global.fetch = originalFetch; });
  for (const dashboard of [adminDashboard({}), workspaceStock({})]) {
    let release;
    global.fetch = async () => new Promise(resolve => { release = resolve; });
    dashboard.destroyNavigation = () => {};
    const pending = dashboard.loadManageStockPaidPage("/next");
    dashboard.destroy();
    release({ ok: true, json: async () => ({
      stockItems: [{ id: 2, status: "PAID", secret: "must-not-survive-unmount" }],
      paidPagination: { previousUrl: "/previous", nextUrl: null },
    }) });
    await pending;
    assert.deepEqual(dashboard.manageStockItems, []);
    assert.deepEqual(dashboard.manageStockPaidPagination, { previousUrl: null, nextUrl: null });
    assert.equal(dashboard.manageStockPaidLoading, false);
  }
});

test("switching product rejects late PAID page success and failure", async (t) => {
  for (const fails of [false, true]) {
    const dashboard = setup(t);
    let release;
    let reject;
    global.fetch = async (url) => {
      if (url === "/old-next") {
        return new Promise((resolve, fail) => { release = resolve; reject = fail; });
      }
      return { ok: true, json: async () => ({
        stockItems: [{ id: 100, status: "PAID", secret: "new-product" }],
        stockSummary: { available: 0, reserved: 0, paid: 1 },
        paidPagination: { previousUrl: null, nextUrl: "/new-next" },
      }) };
    };
    const pending = dashboard.loadManageStockPaidPage("/old-next");
    await dashboard.loadManageStockPayload({ id: 2, stockItemsUrl: "/new-product" });
    if (fails) {
      reject(new Error("old product failure"));
    } else {
      release({ ok: true, json: async () => ({
        stockItems: [{ id: 20, status: "PAID", secret: "wrong-product" }],
        paidPagination: { previousUrl: "/old-previous", nextUrl: null },
      }) });
    }
    await pending;
    assert.deepEqual(dashboard.manageStockItems.map(item => item.secret), ["new-product"]);
    assert.equal(dashboard.manageStockPaidPagination.nextUrl, "/new-next");
    assert.equal(dashboard.manageStockPaidError, "");
    assert.equal(dashboard.manageStockPaidLoading, false);
  }
});
