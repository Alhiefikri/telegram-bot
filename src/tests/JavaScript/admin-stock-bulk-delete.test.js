import assert from "node:assert/strict";
import test from "node:test";
import adminDashboard from "../../resources/js/admin-dashboard.js";

const setup = (t) => {
  const original = {
    fetch: global.fetch,
    confirm: global.confirm,
    document: global.document,
  };
  t.after(() => Object.assign(global, original));
  global.confirm = () => true;
  global.document = { querySelector: () => ({ content: "csrf-test" }) };
  const dashboard = adminDashboard({});
  dashboard.manageStockModalOpen = true;
  dashboard.manageStockBulkDeleteUrl = "/admin/products/1/stock-items";
  dashboard.manageStockItems = [
    { id: 1, editable: true, delete_url: "url", secret: "private-secret" },
    { id: 2, editable: false, delete_url: null },
    { id: 3, editable: true, delete_url: "url" },
  ];
  dashboard.retryManageStockLoad = async () => {};
  return dashboard;
};

test("select all includes only editable available items", (t) => {
  const dashboard = setup(t);
  dashboard.toggleAllManageStockSelection();
  assert.deepEqual(dashboard.manageStockSelectedIds, [1, 3]);
  dashboard.toggleAllManageStockSelection();
  assert.deepEqual(dashboard.manageStockSelectedIds, []);
});

test("cancelled confirmation sends no request", async (t) => {
  const dashboard = setup(t);
  dashboard.manageStockSelectedIds = [1];
  global.confirm = () => false;
  global.fetch = () => assert.fail("No deletion after cancellation");
  await dashboard.deleteSelectedManageStock();
  assert.deepEqual(dashboard.manageStockSelectedIds, [1]);
});

test("in-flight deletion prevents duplicate requests and modal switches; request carries only IDs", async (t) => {
  const dashboard = setup(t);
  dashboard.manageStockSelectedIds = [1, 3];
  let release;
  let calls = 0;
  global.fetch = async (_url, options) => {
    calls++;
    assert.equal(options.headers["X-CSRF-TOKEN"], "csrf-test");
    assert.deepEqual(JSON.parse(options.body), { stock_item_ids: [1, 3] });
    return new Promise((resolve) => {
      release = resolve;
    });
  };
  let refreshes = 0;
  dashboard.retryManageStockLoad = async () => {
    refreshes++;
  };
  const pending = dashboard.deleteSelectedManageStock();
  await dashboard.deleteSelectedManageStock();
  dashboard.closeManageStockModal();
  await dashboard.openManageStockModal({ name: "Other" });
  assert.equal(dashboard.manageStockModalOpen, true);
  assert.equal(calls, 1);
  release({ ok: true, json: async () => ({ deleted_count: 2 }) });
  await pending;
  assert.equal(refreshes, 1);
  assert.equal(dashboard.manageStockDeleting, false);
  assert.deepEqual(dashboard.manageStockSelectedIds, []);
  assert.equal(
    dashboard.manageStockDeleteMessage,
    "2 akun stok berhasil dihapus."
  );
});

test("stale selections, network failure and login redirects refresh without claiming success", async (t) => {
  const dashboard = setup(t);
  for (const response of [
    { ok: false, status: 422, json: async () => ({}) },
    { ok: true, redirected: true, json: async () => ({}) },
    null,
  ]) {
    dashboard.manageStockSelectedIds = [1];
    global.fetch = async () => {
      if (response === null) throw new Error("offline");
      return response;
    };
    await dashboard.deleteSelectedManageStock();
    assert.equal(dashboard.manageStockDeleteMessage, "");
    assert.notEqual(dashboard.manageStockDeleteError, "");
    assert.deepEqual(dashboard.manageStockSelectedIds, []);
    assert.equal(dashboard.manageStockDeleting, false);
  }
});
