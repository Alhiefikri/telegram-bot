import assert from "node:assert/strict";
import test from "node:test";
import adminDashboard from "../../resources/js/admin-dashboard.js";

const setup = (t) => {
  const original = {
    fetch: global.fetch,
    document: global.document,
    confirm: global.confirm,
  };
  t.after(() => Object.assign(global, original));
  global.document = {
    querySelector: () => ({ value: "csrf-test" }),
    getElementById: () => null,
  };
  global.confirm = () => true;
  const dashboard = adminDashboard({
    familyReorderUrl: "/admin/product-families/order",
    familyOrder: [
      {
        id: 1,
        name: "Capcut",
        sort_order: 0,
        edit_payload: { id: 1, sortOrder: 0 },
      },
      {
        id: 2,
        name: "Empty",
        sort_order: 0,
        edit_payload: { id: 2, sortOrder: 0 },
      },
      {
        id: 3,
        name: "Canva",
        sort_order: 1,
        edit_payload: { id: 3, sortOrder: 1 },
      },
    ],
  });
  dashboard.$nextTick = (fn) => fn();
  dashboard.$refs = {};
  dashboard.restoreModalFocus = () => {};
  return dashboard;
};
const ids = (dashboard) => dashboard.familyOrder.map((family) => family.id);

test("up/down moves keep every family, respect boundaries and can roll back", (t) => {
  const dashboard = setup(t);
  dashboard.moveFamily(1, -1);
  dashboard.moveFamily(3, 1);
  assert.deepEqual(ids(dashboard), [1, 2, 3]);
  dashboard.moveFamily(3, -1);
  assert.deepEqual(ids(dashboard), [1, 3, 2]);
  assert.equal(dashboard.familyOrderDirty, true);
  assert.match(dashboard.familyOrderMessage, /Belum disimpan/);
  dashboard.resetFamilyOrder();
  assert.deepEqual(ids(dashboard), [1, 2, 3]);
  assert.equal(dashboard.familyOrderDirty, false);
});

test("direct move places Canva immediately after Capcut and handles first/last targets", (t) => {
  const dashboard = setup(t);
  dashboard.familyMoveId = "3";
  dashboard.familyMoveAfterId = "1";
  dashboard.moveFamilyAfter();
  assert.deepEqual(ids(dashboard), [1, 3, 2]);
  assert.deepEqual(
    dashboard.familyMoveTargets.map((family) => family.id),
    [1, 2]
  );
  dashboard.familyMoveAfterId = "";
  dashboard.moveFamilyAfter();
  assert.deepEqual(ids(dashboard), [3, 1, 2]);
  dashboard.familyMoveAfterId = "2";
  dashboard.moveFamilyAfter();
  assert.deepEqual(ids(dashboard), [1, 2, 3]);
});

test("saving sends full IDs, original snapshot and CSRF once, blocks controls, updates edit values", async (t) => {
  const dashboard = setup(t);
  dashboard.familyModalOpen = true;
  dashboard.moveFamily(3, -1);
  let release;
  let calls = 0;
  global.fetch = async (url, options) => {
    calls++;
    assert.equal(url, "/admin/product-families/order");
    assert.equal(options.method, "PATCH");
    assert.equal(options.headers["X-CSRF-TOKEN"], "csrf-test");
    assert.deepEqual(JSON.parse(options.body), {
      family_ids: [1, 3, 2],
      expected_order: [
        { id: 1, sort_order: 0 },
        { id: 2, sort_order: 0 },
        { id: 3, sort_order: 1 },
      ],
    });
    return new Promise((resolve) => {
      release = resolve;
    });
  };
  const pending = dashboard.saveFamilyOrder();
  await dashboard.saveFamilyOrder();
  dashboard.moveFamily(1, 1);
  dashboard.closeProductFamilyModal();
  dashboard.resetFamilyOrder();
  assert.deepEqual(ids(dashboard), [1, 3, 2]);
  assert.equal(dashboard.familyModalOpen, true);
  assert.equal(calls, 1);
  release({
    ok: true,
    json: async () => ({
      message: "Tersimpan",
      families: [
        { id: 1, sort_order: 0 },
        { id: 3, sort_order: 1 },
        { id: 2, sort_order: 2 },
      ],
    }),
  });
  await pending;
  assert.equal(dashboard.familyOrderSaving, false);
  assert.equal(dashboard.familyOrderDirty, false);
  assert.equal(dashboard.familyOrderMessage, "Tersimpan");
  assert.equal(dashboard.familyOrder[2].edit_payload.sortOrder, 2);
});

test("network failure retains draft for retry without falsely claiming saved order", async (t) => {
  const dashboard = setup(t);
  dashboard.moveFamily(3, -1);
  global.fetch = async () => {
    throw new Error("Failed to fetch");
  };
  await dashboard.saveFamilyOrder();
  assert.deepEqual(ids(dashboard), [1, 3, 2]);
  assert.equal(dashboard.familyOrderDirty, true);
  assert.equal(dashboard.familyOrderMessage, "");
  assert.match(dashboard.familyOrderError, /belum diketahui/);
  global.fetch = async () => ({
    ok: true,
    json: async () => ({
      message: "Tersimpan",
      families: [
        { id: 1, sort_order: 0 },
        { id: 3, sort_order: 1 },
        { id: 2, sort_order: 2 },
      ],
    }),
  });
  await dashboard.saveFamilyOrder();
  assert.equal(dashboard.familyOrderDirty, false);
});

test("stale list prevents retry and movement until reload; reset still discards draft", async (t) => {
  const dashboard = setup(t);
  dashboard.moveFamily(3, -1);
  let calls = 0;
  global.fetch = async () => {
    calls++;
    return { ok: false, status: 409, json: async () => ({}) };
  };
  await dashboard.saveFamilyOrder();
  assert.equal(dashboard.familyOrderStale, true);
  assert.match(dashboard.familyOrderError, /Muat ulang/);
  dashboard.moveFamily(1, 1);
  await dashboard.saveFamilyOrder();
  assert.deepEqual(ids(dashboard), [1, 3, 2]);
  assert.equal(calls, 1);
  dashboard.resetFamilyOrder();
  assert.deepEqual(ids(dashboard), [1, 2, 3]);
  assert.equal(dashboard.familyOrderStale, true);
});

test("login redirects and malformed successes never mark the draft saved", async (t) => {
  const dashboard = setup(t);
  dashboard.moveFamily(3, -1);
  for (const response of [
    { ok: true, redirected: true, json: async () => ({}) },
    { ok: false, status: 419, json: async () => ({}) },
    { ok: true, json: async () => ({ families: [] }) },
  ]) {
    global.fetch = async () => response;
    await dashboard.saveFamilyOrder();
    assert.equal(dashboard.familyOrderDirty, true);
    assert.equal(dashboard.familyOrderMessage, "");
    assert.notEqual(dashboard.familyOrderError, "");
  }
});
