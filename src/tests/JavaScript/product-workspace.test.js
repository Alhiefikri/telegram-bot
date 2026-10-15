import assert from "node:assert/strict";
import test from "node:test";
import productWorkspace, {
  percentToBasisPoints,
  basisPointsToPercent,
  workspaceStock,
} from "../../resources/js/product-workspace.js";

const fixture = () => ({
  product: {
    id: 7,
    name: "Paket <aman>",
    category_id: 2,
    description: "Deskripsi ID",
    description_ms: "",
    description_en: "",
    delivery_note: "Catatan privat",
    price_amount: 15000,
    usdt_price_amount: "1.250",
    quantity_price_tiers: [
      { minimum_quantity: 2, price_amount: 14000, usdt_price_amount: "1.100" },
    ],
    icon: "P",
    is_active: true,
    featured_rank: 0,
    family_id: null,
    family_name: null,
    family_sort_order: 0,
    family_option_label: "",
  },
  versions: {
    detail: "detail-1",
    price: "price-1",
    appearance: "appearance-1",
    family: "family-1",
  },
  policy: {
    version: 3,
    mode: "manual",
    markup_basis_points: 1250,
    profit_idr: 5000,
    minimum_profit_idr: 0,
    buffer_idr: 0,
    rounding_idr: 100,
  },
  urls: {
    workspace: "/workspace",
    update: "/update",
    price: "/policy",
    price_preview: "/preview",
    family_update: "/family",
    catalog_search: "/catalog",
  },
  stock: { summary: { available: 3, reserved: 1, paid: 2 } },
  sources: [],
  stock_payload: {
    id: 7,
    name: "Paket",
    stock: 3,
    stockUrl: "/stock",
    stockAccountsUrl: "/accounts",
    stockAccountsReviewUrl: "/accounts/review",
    stockReturnUrl: "/workspace?section=stock",
  },
  edit_payload: {
    stockItemsUrl: "/stock-items",
    bulkDeleteUrl: "/stock-items/bulk",
  },
  structured_stock_input_enabled: true,
});
const response = (body, status = 200) => ({
  ok: status >= 200 && status < 300,
  status,
  redirected: false,
  json: async () => body,
});
const deferred = () => {
  let resolve;
  const promise = new Promise((done) => {
    resolve = done;
  });
  return { promise, resolve };
};
const create = (
  fetchRequest = async () =>
    response({ workspace: fixture(), message: "Tersimpan" })
) => productWorkspace(fixture(), { fetchRequest });

for (const [input, expected] of [
  ["0", 0],
  ["0.01", 1],
  ["0,1", 10],
  ["12.50", 1250],
  [" 12,34 ", 1234],
  ["999.99", 99999],
  ["1000.00", 100000],
  ["0001.20", 120],
]) {
  test(`human percentage ${JSON.stringify(input)} converts exactly to ${expected} basis points`, () =>
    assert.equal(percentToBasisPoints(input), expected));
}
for (const input of [
  "",
  "-1",
  "+2",
  "1e2",
  "1.001",
  "1,234",
  "1000.01",
  "Infinity",
  "NaN",
  ".5",
  "1.",
  "1,2.3",
  "1 000",
  "9999999999999999999999999",
]) {
  test(`percentage rejects ${JSON.stringify(input)} without rounding`, () =>
    assert.throws(() => percentToBasisPoints(input)));
}
test("basis points format without floating point artifacts and round trip all boundaries", () => {
  for (const value of [0, 1, 9, 10, 99, 100, 101, 1250, 99999, 100000])
    assert.equal(percentToBasisPoints(basisPointsToPercent(value)), value);
  assert.equal(basisPointsToPercent(1250), "12.5");
  for (const value of [-1, 0.5, 100001, "bad"])
    assert.throws(() => basisPointsToPercent(value));
});
test("all accordions start collapsed and exactly one can open", () => {
  const state = create();
  assert.equal(state.openSection, null);
  state.toggle("detail");
  assert.equal(state.openSection, "detail");
  state.toggle("price");
  assert.equal(state.openSection, "price");
  state.toggle("price");
  assert.equal(state.openSection, null);
  state.toggle("unknown");
  assert.equal(state.openSection, null);
});
test("explicit error section opens while unsupported section stays collapsed", () => {
  const payload = fixture();
  payload.initial_section = "price";
  assert.equal(productWorkspace(payload).openSection, "price");
  payload.initial_section = "constructor";
  assert.equal(productWorkspace(payload).openSection, null);
});
test("collapse preserves multilingual text, private delivery note, tiers and file references", () => {
  const state = create();
  const file = { name: "cover.webp" };
  state.files.image = file;
  state.draft.detail.description_en = "English draft";
  state.draft.detail.delivery_note = "Private draft";
  state.draft.price.quantity_price_tiers[0].price_amount = 13000;
  state.toggle("detail");
  state.toggle("appearance");
  state.toggle("appearance");
  assert.equal(state.draft.detail.description_en, "English draft");
  assert.equal(state.draft.detail.delivery_note, "Private draft");
  assert.equal(state.draft.price.quantity_price_tiers[0].price_amount, 13000);
  assert.equal(state.files.image, file);
  assert.equal(state.sectionDirty("detail"), true);
  assert.equal(state.sectionDirty("price"), true);
  assert.equal(state.sectionDirty("appearance"), true);
});
test("drafts do not mutate server payload or saved tier arrays", () => {
  const payload = fixture(),
    state = productWorkspace(payload);
  state.draft.price.quantity_price_tiers[0].price_amount = 1;
  assert.equal(payload.product.quantity_price_tiers[0].price_amount, 14000);
  assert.equal(state.saved.price.quantity_price_tiers[0].price_amount, 14000);
});
test("dirty state clears only when edited data equals saved state", () => {
  const state = create();
  assert.equal(state.hasUnsaved(), false);
  state.draft.detail.name = "Other";
  assert.equal(state.hasUnsaved(), true);
  state.draft.detail.name = state.saved.detail.name;
  assert.equal(state.hasUnsaved(), false);
  state.draft.family.family_option_label = "Opsi";
  assert.equal(state.sectionDirty("detail"), true);
  state.draft.policy.mode = "fixed";
  assert.equal(state.sectionDirty("price"), true);
});
test("unload guard registers and removes listener and prompts only while dirty", (t) => {
  const originalWindow = globalThis.window;
  t.after(() => {
    globalThis.window = originalWindow;
  });
  const listeners = new Map();
  globalThis.window = {
    addEventListener: (key, fn) => listeners.set(key, fn),
    removeEventListener: (key) => listeners.delete(key),
  };
  const state = create();
  state.init();
  let prompts = 0;
  const event = {
    preventDefault() {
      prompts++;
    },
  };
  listeners.get("beforeunload")(event);
  assert.equal(prompts, 0);
  state.externalDirty = true;
  listeners.get("beforeunload")(event);
  assert.equal(prompts, 1);
  assert.equal(event.returnValue, "");
  state.destroy();
  assert.equal(listeners.size, 0);
});
test("detail save posts only owned fields with original section token", async () => {
  let request;
  const next = fixture();
  next.product.name = "Saved";
  next.versions.detail = "detail-2";
  const state = create(async (url, options) => {
    request = { url, ...options };
    return response({ message: "Detail tersimpan", workspace: next });
  });
  state.draft.detail.name = "Saved";
  state.draft.price.price_amount = 9999;
  assert.equal(await state.save("detail"), true);
  const body = JSON.parse(request.body);
  assert.equal(request.url, "/update");
  assert.equal(body.workspace_section, "detail");
  assert.equal(body.workspace_version, "detail-1");
  assert.equal(body._method, "PATCH");
  assert.equal(body.name, "Saved");
  assert.equal("price_amount" in body, false);
  assert.equal("icon" in body, false);
  assert.equal(state.dirty("detail"), false);
  assert.equal(state.draft.price.price_amount, 9999);
  assert.equal(state.dirty("price"), true);
  assert.equal(state.feedback.detail, "Detail tersimpan");
});
test("manual price save retains USDT and tier schedule without posting supplier policy", async () => {
  let body;
  const state = create(async (_url, options) => {
    body = JSON.parse(options.body);
    return response({ workspace: fixture() });
  });
  await state.save("price");
  assert.equal(body.quantity_price_tiers_present, 1);
  assert.equal(body.usdt_price_amount, "1.250");
  assert.equal(body.quantity_price_tiers[0].usdt_price_amount, "1.100");
  assert.equal("mode" in body, false);
  assert.equal("name" in body, false);
});
test("policy uses policy version and exact basis points, never overwrites manual/tier fields", async () => {
  let body, url;
  const state = create(async (target, options) => {
    url = target;
    body = JSON.parse(options.body);
    return response({ workspace: fixture() });
  });
  state.draft.policy.markup_percent = "12,34";
  state.draft.policy.mode = "hybrid";
  await state.save("policy");
  assert.equal(url, "/policy");
  assert.equal(body.markup_basis_points, 1234);
  assert.equal(body.version, 3);
  assert.equal(body.mode, "hybrid");
  assert.equal("markup_percent" in body, false);
  assert.equal("price_amount" in body, false);
  assert.equal("quantity_price_tiers" in body, false);
});
test("invalid percentage opens price accordion with local field error and no request", async () => {
  const state = create(() => assert.fail("invalid percentage must not post"));
  state.draft.policy.mode = "percent";
  state.draft.policy.markup_percent = "12.345";
  assert.equal(await state.save("policy"), false);
  assert.match(state.error("policy", "markup_percent"), /2 angka/);
  assert.equal(state.openSection, "price");
  assert.equal(state.dirty("policy"), true);
});
test("family grouping sends existing field names and does not post unrelated product edits", async () => {
  let body, target;
  const state = create(async (url, options) => {
    target = url;
    body = JSON.parse(options.body);
    return response({ workspace: fixture() });
  });
  state.draft.family.family_id = 4;
  state.draft.family.family_option_label = "Bulanan";
  await state.save("family");
  assert.equal(target, "/family");
  assert.equal(body.workspace_version, "family-1");
  assert.equal(body.product_family_id, 4);
  assert.equal(body.family_option_label, "Bulanan");
  assert.equal("family_id" in body, false);
  assert.equal("name" in body, false);
});
for (const [label, fetchRequest] of [
  [
    "422 validation",
    async () =>
      response(
        { message: "Periksa nama", errors: { name: ["Nama wajib diisi."] } },
        422
      ),
  ],
  ["409 stale write", async () => response({ message: "Conflict" }, 409)],
  [
    "network failure",
    async () => {
      throw new Error("Offline");
    },
  ],
  [
    "unexpected login redirect",
    async () => ({ ...response({}), redirected: true }),
  ],
  [
    "malformed successful response",
    async () => response({ message: "No workspace" }),
  ],
])
  test(`${label} preserves unsaved fields, token and dirty state`, async () => {
    const state = create(fetchRequest);
    state.draft.detail.name = "Unsaved";
    state.draft.price.price_amount = 123;
    assert.equal(await state.save("detail"), false);
    assert.equal(state.draft.detail.name, "Unsaved");
    assert.equal(state.draft.price.price_amount, 123);
    assert.equal(state.workspace.versions.detail, "detail-1");
    assert.equal(state.dirty("detail"), true);
    assert.equal(state.saving.detail, false);
    assert.equal(state.openSection, "detail");
    assert.ok(state.feedback.detail);
  });
test("server localized field errors select matching locale and expose optional specifications", async () => {
  const state = create(async () =>
    response(
      {
        errors: {
          description_en: ["English too long"],
          device_limit: ["Too long"],
        },
      },
      422
    )
  );
  await state.save("detail");
  assert.equal(state.contentLocale, "en");
  assert.equal(state.error("detail", "description_en"), "English too long");
  assert.equal(state.specificationErrors(), true);
});
test("server basis point validation maps back to the human percent field", async () => {
  const state = create(async () =>
    response({ errors: { markup_basis_points: ["Di luar batas"] } }, 422)
  );
  await state.save("policy");
  assert.equal(state.error("policy", "markup_percent"), "Di luar batas");
});
test("typing during save does not lose newer draft after successful old submission", async () => {
  const pending = deferred(),
    state = create(() => pending.promise);
  state.draft.detail.name = "Submitted";
  const save = state.save("detail");
  state.draft.detail.name = "Newer typing";
  const payload = fixture();
  payload.product.name = "Submitted";
  payload.versions.detail = "detail-2";
  pending.resolve(response({ workspace: payload }));
  await save;
  assert.equal(state.saved.detail.name, "Submitted");
  assert.equal(state.draft.detail.name, "Newer typing");
  assert.equal(state.dirty("detail"), true);
  assert.equal(state.workspace.versions.detail, "detail-2");
});
test("save requests are single flight across sections and preserve second unsaved form", async () => {
  const pending = deferred();
  let calls = 0;
  const state = create(() => {
    calls++;
    return pending.promise;
  });
  state.draft.detail.name = "One";
  state.draft.policy.profit_idr = 6000;
  const first = state.save("detail");
  assert.equal(await state.save("detail"), false);
  assert.equal(await state.save("policy"), false);
  assert.equal(calls, 1);
  pending.resolve(response({ workspace: fixture() }));
  await first;
  assert.equal(state.draft.policy.profit_idr, 6000);
  assert.equal(state.dirty("policy"), true);
});
test("obsolete response cannot mutate saved form or clear newer request loading state", async () => {
  const pending = deferred(),
    state = create(() => pending.promise);
  state.draft.detail.name = "Draft";
  const save = state.save("detail");
  state.requests.detail++;
  pending.resolve(response({ workspace: fixture() }));
  assert.equal(await save, false);
  assert.equal(state.draft.detail.name, "Draft");
  assert.equal(state.saving.detail, true);
  assert.equal(state.feedback.detail, "");
});
test("supplier refresh updates summaries but preserves dirty drafts and original conflict tokens", () => {
  const state = create();
  state.draft.detail.name = "Name draft";
  state.draft.policy.profit_idr = 6500;
  const payload = fixture();
  payload.product.name = "Remote name";
  payload.product.price_amount = 19000;
  payload.versions.detail = "remote";
  payload.policy.version = 4;
  payload.stock.summary.available = 9;
  state.mergeWorkspace(payload);
  assert.equal(state.draft.detail.name, "Name draft");
  assert.equal(state.saved.detail.name, "Paket <aman>");
  assert.equal(state.workspace.versions.detail, "detail-1");
  assert.equal(state.draft.price.price_amount, 19000);
  assert.equal(state.draft.policy.profit_idr, 6500);
  assert.equal(state.workspace.policy.version, 3);
  assert.match(state.summary("stock"), /9 tersedia/);
});
test("concurrent workspace refreshes share one GET and merge once", async () => {
  const pending = deferred();
  let calls = 0;
  const state = create(() => {
    calls++;
    return pending.promise;
  });
  const a = state.refresh(),
    b = state.refresh();
  assert.equal(calls, 1);
  const latest = fixture();
  latest.product.name = "Newest";
  pending.resolve(response(latest));
  await Promise.all([a, b]);
  assert.equal(state.workspace.product.name, "Newest");
});
test("a save invalidates previously started refresh so it cannot overwrite newly saved state", async () => {
  const old = deferred();
  const next = fixture();
  next.product.name = "Saved";
  const state = create(async (url) =>
    url === "/workspace" ? old.promise : response({ workspace: next })
  );
  const refresh = state.refresh();
  state.draft.detail.name = "Saved";
  await state.save("detail");
  old.resolve(response(fixture()));
  await refresh;
  assert.equal(state.workspace.product.name, "Saved");
});
test("stock refresh requested during save is deferred until mutation finishes", async () => {
  const pending = deferred();
  let refreshes = 0;
  const state = create(async (url) => {
    if (url !== "/workspace") return pending.promise;
    refreshes++;
    const payload = fixture();
    payload.stock.summary.available = 12;
    return response(payload);
  });
  const save = state.save("detail");
  await state.refresh();
  assert.equal(refreshes, 0);
  pending.resolve(response({ workspace: fixture() }));
  await save;
  assert.equal(refreshes, 1);
  assert.equal(state.workspace.stock.summary.available, 12);
});
test("section reload after stale conflict discards only selected form with confirmation", async (t) => {
  const original = globalThis.confirm;
  t.after(() => {
    globalThis.confirm = original;
  });
  globalThis.confirm = () => true;
  const payload = fixture();
  payload.product.name = "Remote";
  payload.versions.detail = "detail-9";
  const state = create(async () => response(payload));
  state.draft.detail.name = "Discard";
  state.draft.price.price_amount = 123;
  assert.equal(await state.reloadForm("detail"), true);
  assert.equal(state.draft.detail.name, "Remote");
  assert.equal(state.workspace.versions.detail, "detail-9");
  assert.equal(state.draft.price.price_amount, 123);
  assert.equal(state.dirty("detail"), false);
});
test("cancelled reload leaves every draft and token unchanged and performs no request", async (t) => {
  const original = globalThis.confirm;
  t.after(() => {
    globalThis.confirm = original;
  });
  globalThis.confirm = () => false;
  const state = create(() => assert.fail("cancelled reload"));
  state.draft.detail.name = "Keep";
  assert.equal(await state.reloadForm("detail"), false);
  assert.equal(state.draft.detail.name, "Keep");
});
test("five-tier cap and removal retain remaining schedule", () => {
  const state = create();
  for (let i = 0; i < 8; i++) state.addTier();
  assert.equal(state.draft.price.quantity_price_tiers.length, 5);
  state.draft.price.quantity_price_tiers.splice(1, 1);
  assert.equal(state.draft.price.quantity_price_tiers[0].price_amount, 14000);
  assert.equal(state.dirty("price"), true);
});
test("supplier attach requires explicit equivalence and selection and sends optimistic versions", async () => {
  let body, target;
  const state = create(async (url, options) => {
    target = url;
    body = JSON.parse(options.body);
    return response({ workspace: fixture(), message: "Ditambahkan" });
  });
  let closed = 0;
  state.$refs = {
    supplierDialog: {
      close() {
        closed++;
      },
    },
  };
  state.catalog = [
    { id: 10, map_url: "/map/10", supplier_version: 9, version: 0 },
  ];
  state.catalogSelection = "10";
  assert.equal(await state.attachSupplier(), false);
  state.equivalentConfirmed = true;
  assert.equal(await state.attachSupplier(), true);
  assert.equal(target, "/map/10");
  assert.equal(body.supplier_version, 9);
  assert.equal(body.version, 0);
  assert.equal(body.product_id, 7);
  assert.equal(body.equivalent_confirmed, 1);
  assert.equal(closed, 1);
  assert.equal(state.catalogSelection, "");
  assert.equal(state.equivalentConfirmed, false);
});
test("failed supplier attach keeps selection and equivalence confirmation for retry", async () => {
  const state = create(async () =>
    response(
      { message: "Versi berubah", errors: { version: ["Kedaluwarsa"] } },
      409
    )
  );
  state.catalog = [{ id: 10, map_url: "/map", supplier_version: 9 }];
  state.catalogSelection = "10";
  state.equivalentConfirmed = true;
  assert.equal(await state.attachSupplier(), false);
  assert.equal(state.catalogSelection, "10");
  assert.equal(state.equivalentConfirmed, true);
  assert.equal(state.saving.supplier, false);
  assert.equal(state.error("supplier", "version"), "Kedaluwarsa");
});
test("supplier disable, move and detach send existing version and restriction contracts", async (t) => {
  const original = globalThis.confirm;
  t.after(() => {
    globalThis.confirm = original;
  });
  globalThis.confirm = () => true;
  const requests = [];
  const state = create(async (url, options) => {
    requests.push({
      url,
      method: options.method,
      body: JSON.parse(options.body),
    });
    return response({ workspace: fixture() });
  });
  const source = {
    id: 4,
    version: 6,
    supplier_version: 9,
    priority: 2,
    is_active: true,
    urls: { map: "/map", move: "/move", detach: "/detach" },
  };
  await state.sourceAction(source, "map");
  await state.sourceAction(source, "move", "up");
  await state.sourceAction(source, "detach");
  assert.equal(requests[0].body.is_active, 0);
  assert.equal(requests[1].body.direction, "up");
  assert.equal(requests[2].method, "DELETE");
  assert.equal(requests[2].body.source_id, 4);
  assert.equal(requests[2].body.version, 6);
  assert.equal(requests[2].body.supplier_version, 9);
});
test("cancelled historical mapping detach confirmation never sends request", async (t) => {
  const original = globalThis.confirm;
  t.after(() => {
    globalThis.confirm = original;
  });
  globalThis.confirm = () => false;
  const state = create(() => assert.fail("Cancelled detach"));
  await state.sourceAction({ urls: {} }, "detach");
});
test("catalog searches encode user input and ignore stale results and stale errors", async () => {
  const first = deferred(),
    second = deferred();
  const urls = [];
  const state = create((url) => {
    urls.push(url);
    return urls.length === 1 ? first.promise : second.promise;
  });
  state.catalogQuery = "a & b";
  const a = state.searchCatalog();
  state.catalogQuery = "new";
  const b = state.searchCatalog(2);
  second.resolve(
    response({
      items: [{ id: 2 }],
      pagination: { current_page: 2, last_page: 3 },
    })
  );
  await b;
  first.resolve(response({ message: "old failure" }, 500));
  await a;
  assert.match(urls[0], /q=a\+%26\+b/);
  assert.deepEqual(state.catalog, [{ id: 2 }]);
  assert.equal(state.catalogPage, 2);
  assert.equal(state.catalogError, "");
  assert.equal(state.catalogLoading, false);
});
test("catalog network failure exposes retry state without retaining misleading prior results", async () => {
  const state = create(async () => {
    throw new Error("Offline catalog");
  });
  state.catalog = [{ id: 1 }];
  await state.searchCatalog();
  assert.equal(state.catalogError, "Offline catalog");
  assert.deepEqual(state.catalog, []);
  assert.equal(state.catalogLoading, false);
});
test("price preview is server backed, does not mutate saved data and retains manual USDT tiers", async () => {
  let body;
  const state = create(async (_url, options) => {
    body = JSON.parse(options.body);
    return response({ cost_idr: 10000, retail_idr: 12500 });
  });
  state.draft.policy.mode = "percent";
  state.draft.policy.markup_percent = "25";
  state.previewCatalogId = 10;
  await state.previewPrice();
  assert.equal(body.markup_basis_points, 2500);
  assert.equal(body.catalog_item_id, 10);
  assert.equal(state.preview.retail_idr, 12500);
  assert.equal(state.workspace.product.price_amount, 15000);
  assert.equal(state.draft.price.usdt_price_amount, "1.250");
  assert.equal(state.dirty("policy"), true);
});
test("stale preview response cannot replace newer requested SKU preview", async () => {
  const a = deferred(),
    b = deferred();
  let calls = 0;
  const state = create(() => (++calls === 1 ? a.promise : b.promise));
  const first = state.previewPrice(),
    second = state.previewPrice();
  b.resolve(response({ retail_idr: 20 }));
  await second;
  a.resolve(response({ retail_idr: 10 }));
  await first;
  assert.equal(state.preview.retail_idr, 20);
});

const stockSetup = (t, fetchRequest) => {
  const originalFormData = globalThis.FormData,
    originalDocument = globalThis.document;
  t.after(() => {
    globalThis.FormData = originalFormData;
    globalThis.document = originalDocument;
  });
  globalThis.FormData = class {
    constructor(form) {
      this.form = form;
      this.values = new Map();
    }
    set(key, value) {
      this.values.set(key, value);
    }
  };
  globalThis.document = { querySelector: () => ({ content: "csrf" }) };
  const state = workspaceStock(fixture(), { fetchRequest });
  state.$nextTick = (fn) => fn();
  state.$refs = { stockItems: { focus() {} } };
  state.restoreModalFocus = () => {};
  state.$dispatch = (name, body) => state.events.push({ name, body });
  state.events = [];
  return state;
};
test("nested stock wrapper keeps both management URLs and structured intake capability", () => {
  const state = workspaceStock(fixture());
  assert.equal(state.workspaceStockPayload.stockItemsUrl, "/stock-items");
  assert.equal(
    state.workspaceStockPayload.stockAccountsReviewUrl,
    "/accounts/review"
  );
  assert.equal(state.structuredStockInputEnabled, true);
});
test("quick stock failed JSON save retains secret input, modal and operation key and exposes field errors", async (t) => {
  const state = stockSetup(t, async () =>
    response(
      {
        message: "Format salah",
        errors: { stock_items: ["Baris 2 tidak valid"] },
      },
      422
    )
  );
  state.stockModalOpen = true;
  state.stockItems = "private-secret";
  state.stockOperationKey = "stable-operation";
  let focused = 0;
  state.$refs.stockItems.focus = () => focused++;
  await state.submitStockForm({
    preventDefault() {},
    target: { action: "/stock", checkValidity: () => true },
  });
  assert.equal(state.stockItems, "private-secret");
  assert.equal(state.stockOperationKey, "stable-operation");
  assert.equal(state.stockModalOpen, true);
  assert.equal(state.stockFormSubmitting, false);
  assert.equal(state.workspaceStockMessage, "Format salah");
  assert.equal(state.manageStockError("stock_items"), "Baris 2 tidak valid");
  assert.equal(focused, 1);
  assert.equal(
    state.events.some((event) => event.name === "workspace-stock-saved"),
    false
  );
});
test("quick stock network failure keeps credentials memory only and never navigates", async (t) => {
  const state = stockSetup(t, async () => {
    throw new Error("Offline");
  });
  state.stockModalOpen = true;
  state.stockItems = "keep-secret";
  await state.submitWorkspaceStockForm({ action: "/stock" }, true);
  assert.equal(state.stockItems, "keep-secret");
  assert.equal(state.stockModalOpen, true);
  assert.equal(state.workspaceStockMessage, "Offline");
});
test("quick stock success closes only intake and emits refresh event without navigating", async (t) => {
  const state = stockSetup(t, async () =>
    response({ message: "2 akun tersimpan", workspace: fixture() })
  );
  state.stockModalOpen = true;
  state.stockItems = "secret";
  await state.submitWorkspaceStockForm({ action: "/stock" }, true);
  assert.equal(state.stockModalOpen, false);
  assert.equal(state.stockItems, "");
  assert.equal(state.stockOperationKey, "");
  assert.equal(
    state.events.find((event) => event.name === "workspace-stock-saved").body
      .message,
    "2 akun tersimpan"
  );
});
test("stock management save refreshes existing manager and preserves readonly restrictions", async (t) => {
  const state = stockSetup(t, async () =>
    response({ message: "Akun diperbarui" })
  );
  let refreshed = 0;
  state.retryManageStockLoad = async () => {
    refreshed++;
  };
  await state.submitWorkspaceStockForm({ action: "/stock-items/1" });
  assert.equal(refreshed, 1);
  assert.equal(state.manageStockDeleting, false);
  assert.equal(state.workspaceStockMessage, "Akun diperbarui");
});
test("stock submit bridge respects cancelled delete confirmation and leaves structured flow alone", (t) => {
  const state = stockSetup(t, () => assert.fail("No request"));
  const events = [
    { defaultPrevented: true, target: {} },
    { defaultPrevented: false, target: { closest: () => true } },
    {
      defaultPrevented: false,
      target: { closest: () => false, querySelector: () => true },
    },
  ];
  for (const event of events) state.captureStockSubmit(event);
});
test("stock busy guard prevents duplicate POST and closing during save", async (t) => {
  const pending = deferred();
  let calls = 0;
  const state = stockSetup(t, () => {
    calls++;
    return pending.promise;
  });
  state.stockModalOpen = true;
  const first = state.submitWorkspaceStockForm({ action: "/stock" }, true);
  await state.submitWorkspaceStockForm({ action: "/stock" }, true);
  state.closeStockModal();
  assert.equal(calls, 1);
  assert.equal(state.stockModalOpen, true);
  pending.resolve(response({ message: "Saved" }));
  await first;
  assert.equal(state.workspaceStockBusy, false);
});
test("structured stock callback refreshes workspace instead of assigning a redirect", async (t) => {
  const state = stockSetup(t, () => assert.fail("Callback needs no request"));
  await state.onStockSaved({ item_count: 2, redirect_url: "/dashboard" });
  assert.equal(
    state.events.some((event) => event.name === "workspace-stock-saved"),
    true
  );
});
test("stock editing and credentials drive unload dirty event without storing secrets", (t) => {
  const state = stockSetup(t, () => {});
  state.stockItems = "secret";
  state.emitStockDirty();
  assert.equal(state.events.at(-1).body.dirty, true);
  assert.deepEqual(Object.keys(state.events.at(-1).body), ["dirty"]);
  state.stockItems = "";
  state.accountCards = [];
  state.manageStockItems = [];
  state.emitStockDirty();
  assert.equal(state.events.at(-1).body.dirty, false);
});

test("existing merchandising lists display as multiline strings and survive unrelated saves", async () => {
  const payload = fixture();
  payload.product.included_items = ["Satu", "Dua"];
  payload.product.limitations = ["Batas satu", "Batas dua"];
  const state = productWorkspace(payload, {
    fetchRequest: async () => response({ workspace: payload }),
  });
  assert.equal(state.draft.detail.included_items, "Satu\nDua");
  assert.equal(state.saved.detail.limitations, "Batas satu\nBatas dua");
  state.draft.detail.included_items += "\nTiga";
  await state.save("price");
  assert.equal(state.draft.detail.included_items, "Satu\nDua\nTiga");
  assert.equal(state.saved.detail.included_items, "Satu\nDua");
});
for (const mode of ["manual", "fixed"])
  test(`switching invalid hidden percent to ${mode} uses prior basis points`, async () => {
    let submitted;
    const state = create(async (_url, options) => {
      submitted = JSON.parse(options.body);
      return response({ workspace: fixture() });
    });
    state.draft.policy.mode = mode;
    state.draft.policy.markup_percent = "not a percent";
    if (mode === "manual") state.draft.policy.profit_idr = "not a profit";
    assert.equal(await state.save("policy"), true);
    assert.equal(submitted.markup_basis_points, 1250);
    if (mode === "manual") assert.equal(submitted.profit_idr, 5000);
  });
test("hybrid mode validates visible percent rather than silently using stored basis points", async () => {
  const state = create(() =>
    assert.fail("Invalid hybrid percent must not post")
  );
  state.draft.policy.mode = "hybrid";
  state.draft.policy.markup_percent = "1000.001";
  assert.equal(await state.save("policy"), false);
  assert.ok(state.error("policy", "markup_percent"));
});
test("appearance summary distinguishes global placeholder from custom product image", () => {
  const state = create();
  state.workspace.product.image_url = "/placeholder.webp";
  state.workspace.product.image_path = null;
  assert.match(state.summary("appearance"), /gambar default/);
  state.workspace.product.image_path = "products/custom.webp";
  assert.match(state.summary("appearance"), /Gambar toko diatur/);
});
test("file selection rejects unsupported types and oversize without keeping file references", (t) => {
  const originalCreate = URL.createObjectURL,
    originalRevoke = URL.revokeObjectURL;
  t.after(() => {
    URL.createObjectURL = originalCreate;
    URL.revokeObjectURL = originalRevoke;
  });
  URL.createObjectURL = () => "blob:chosen";
  URL.revokeObjectURL = () => {};
  const state = create();
  for (const file of [
    { name: "bad.svg", type: "image/svg+xml", size: 20 },
    { name: "huge.png", type: "image/png", size: 5 * 1024 * 1024 + 1 },
  ]) {
    const target = { files: [file], value: "chosen" };
    state.selectFile("image", { target });
    assert.equal(target.value, "");
    assert.equal(state.files.image, undefined);
    assert.match(state.error("appearance", "image"), /5 MB/);
  }
  const file = {
    name: "valid.webp",
    type: "image/webp",
    size: 5 * 1024 * 1024,
  };
  state.selectFile("telegram_channel_image", { target: { files: [file] } });
  assert.equal(state.files.telegram_channel_image, file);
  assert.equal(state.filePreviews.telegram_channel_image, "blob:chosen");
  assert.equal(state.dirty("appearance"), true);
});
test("appearance multipart failure keeps all three chosen images and original conflict token", async (t) => {
  const OriginalFormData = globalThis.FormData;
  t.after(() => {
    globalThis.FormData = OriginalFormData;
  });
  globalThis.FormData = class {
    constructor() {
      this.values = new Map();
    }
    append(key, value) {
      this.values.set(key, value);
    }
  };
  let posted;
  const state = create(async (_url, options) => {
    posted = options;
    return response({ errors: { image: ["Upload gagal"] } }, 422);
  });
  const files = {
    image: { name: "web.webp" },
    telegram_image: { name: "bot.png" },
    telegram_channel_image: { name: "channel.jpg" },
  };
  state.files = { ...files };
  state.draft.appearance.is_active = false;
  assert.equal(await state.save("appearance"), false);
  assert.equal(posted.body.values.get("is_active"), "0");
  assert.equal(posted.body.values.get("workspace_version"), "appearance-1");
  assert.equal(posted.headers["Content-Type"], undefined);
  for (const [key, file] of Object.entries(files)) {
    assert.equal(posted.body.values.get(key), file);
    assert.equal(state.files[key], file);
  }
  assert.equal(state.draft.appearance.is_active, false);
  assert.equal(state.dirty("appearance"), true);
});
test("keyboard locale tabs cycle and Home/End choose first and final locale", () => {
  const state = create();
  let prevented = 0;
  const event = (key) => ({
    key,
    preventDefault() {
      prevented++;
    },
  });
  state.localeKeydown(event("ArrowLeft"), "id");
  assert.equal(state.contentLocale, "en");
  state.localeKeydown(event("ArrowRight"), "en");
  assert.equal(state.contentLocale, "id");
  state.localeKeydown(event("End"), "id");
  assert.equal(state.contentLocale, "en");
  state.localeKeydown(event("Home"), "en");
  assert.equal(state.contentLocale, "id");
  assert.equal(prevented, 4);
});

test("stock success uses returned snapshot without another workspace GET", async () => {
  const state = create(() => assert.fail("snapshot already returned"));
  state.draft.detail.name = "Keep draft";
  const next = fixture();
  next.stock.summary.available = 8;
  await state.acceptStockSaved({ message: "Saved stock", workspace: next });
  assert.equal(state.workspace.stock.summary.available, 8);
  assert.equal(state.draft.detail.name, "Keep draft");
  assert.equal(state.feedback.stock, "Saved stock");
});

test("stock mutation fences an older GET and blocks form mutations until finished", async () => {
  const pending = deferred();
  const state = create(() => pending.promise);
  const refresh = state.refresh();
  state.stockBusyChanged(true);
  state.draft.detail.name = "Keep";
  assert.equal(await state.save("detail"), false);
  pending.resolve(response(fixture()));
  await refresh;
  assert.equal(state.draft.detail.name, "Keep");
  await state.stockBusyChanged(false);
  assert.equal(state.mutationBusy(), false);
});

test("reload drains a stock refresh deferred during its GET", async (t) => {
  const original = globalThis.confirm;
  t.after(() => {
    globalThis.confirm = original;
  });
  globalThis.confirm = () => true;
  const pending = deferred();
  let calls = 0;
  const state = create(() =>
    ++calls === 1 ? pending.promise : response(fixture())
  );
  const reload = state.reloadForm("detail");
  await state.refresh();
  pending.resolve(response(fixture()));
  await reload;
  assert.equal(calls, 2);
  assert.equal(state.refreshPending, false);
});

test("closing catalog fences pending results and reopening cannot select prior rows", async () => {
  const first = deferred(),
    second = deferred();
  let calls = 0;
  const state = create(() => (++calls === 1 ? first.promise : second.promise));
  state.openSupplierDialog();
  state.catalog = [{ id: 1, map_url: "/old" }];
  state.catalogSelection = "1";
  state.equivalentConfirmed = true;
  state.closeSupplierDialog();
  state.openSupplierDialog();
  assert.deepEqual(state.catalog, []);
  assert.equal(state.catalogSelection, "");
  assert.equal(state.equivalentConfirmed, false);
  first.resolve(
    response({
      items: [{ id: 1 }],
      pagination: { current_page: 1, last_page: 1 },
    })
  );
  await new Promise((resolve) => setImmediate(resolve));
  assert.deepEqual(state.catalog, []);
  second.resolve(
    response({
      items: [{ id: 2 }],
      pagination: { current_page: 1, last_page: 1 },
    })
  );
  await new Promise((resolve) => setImmediate(resolve));
  assert.deepEqual(state.catalog, [{ id: 2 }]);
});

test("catalog response is rejected when query changes before debounce starts next search", async () => {
  const pending = deferred();
  const state = create(() => pending.promise);
  state.catalogQuery = "old";
  state.equivalentConfirmed = true;
  const search = state.searchCatalog();
  assert.equal(state.equivalentConfirmed, false);
  state.catalogQuery = "new";
  pending.resolve(
    response({
      items: [{ id: 1 }],
      pagination: { current_page: 1, last_page: 1 },
    })
  );
  await search;
  assert.deepEqual(state.catalog, []);
  assert.equal(state.catalogLoading, false);
});

test("successful attach from closed dialog cannot close a newer dialog session", async () => {
  const pending = deferred();
  const state = create((url) =>
    url === "/map"
      ? pending.promise
      : response({ items: [], pagination: { current_page: 1, last_page: 1 } })
  );
  state.openSupplierDialog();
  await new Promise((resolve) => setImmediate(resolve));
  state.catalog = [{ id: 1, map_url: "/map", supplier_version: 1 }];
  state.catalogSelection = "1";
  state.equivalentConfirmed = true;
  const attach = state.attachSupplier();
  state.closeSupplierDialog();
  state.openSupplierDialog();
  pending.resolve(response({ workspace: fixture() }));
  await attach;
  assert.equal(state.supplierDialogOpen, true);
});

test("policy or SKU edits before preview response do not display obsolete simulation", async () => {
  for (const edit of [
    (state) => {
      state.draft.policy.profit_idr = 9000;
    },
    (state) => {
      state.previewCatalogId = 22;
    },
  ]) {
    const pending = deferred();
    const state = create(() => pending.promise);
    const preview = state.previewPrice();
    edit(state);
    pending.resolve(response({ retail_idr: 1 }));
    await preview;
    assert.equal(state.preview, null);
    assert.equal(state.previewLoading, false);
  }
});

test("destroy fences workspace, catalog, save and preview responses", async () => {
  for (const start of [
    (state) => state.refresh(),
    (state) => state.save("detail"),
    (state) => state.reloadForm("detail"),
    (state) => state.mutateSupplier("/map", {}),
    (state) => state.searchCatalog(),
    (state) => state.previewPrice(),
  ]) {
    const original = globalThis.confirm;
    globalThis.confirm = () => true;
    const pending = deferred();
    const state = create(() => pending.promise);
    let events = 0;
    state.$dispatch = () => events++;
    const work = start(state);
    globalThis.confirm = original;
    state.destroy();
    const next = fixture();
    next.product.name = "Must not apply";
    pending.resolve(
      response({
        workspace: next,
        items: [{ id: 1 }],
        pagination: { current_page: 1, last_page: 1 },
        preview: { retail_idr: 1 },
      })
    );
    await work;
    assert.equal(state.workspace.product.name, fixture().product.name);
    assert.deepEqual(state.catalog, []);
    assert.equal(state.preview, null);
    assert.equal(events, 0);
  }
});

test("stock destruction clears credentials and fences successful in-flight POST", async (t) => {
  const pending = deferred();
  const state = stockSetup(t, () => pending.promise);
  state.stockItems = "secret";
  state.accountCards = [
    {
      email: "private@example.test",
      password: "private",
      accessValue: "token",
    },
  ];
  state.manageStockItems = [{ login: "secret" }];
  state.destroyNavigation = () => {};
  const save = state.submitWorkspaceStockForm({ action: "/stock" }, true);
  state.destroy();
  pending.resolve(response({ message: "Saved" }));
  await save;
  assert.equal(state.stockItems, "");
  assert.deepEqual(state.accountCards, []);
  assert.deepEqual(state.manageStockItems, []);
  assert.equal(
    state.events.some((event) => event.name === "workspace-stock-saved"),
    false
  );
});

test("stock and form mutations share a synchronous gate in both directions", async (t) => {
  const pending = deferred();
  const parent = create(() => assert.fail("form save blocked"));
  const state = stockSetup(t, () => pending.promise);
  state.mutationBusy = () => parent.mutationBusy();
  const dispatch = state.$dispatch;
  state.$dispatch = (name, body) => {
    dispatch(name, body);
    if (name === "workspace-stock-busy") parent.stockBusyChanged(body.busy);
    if (name === "workspace-stock-saved") parent.acceptStockSaved(body);
  };
  const save = state.submitWorkspaceStockForm({ action: "/stock" }, true);
  assert.equal(parent.saving.stock, true);
  assert.equal(await parent.save("detail"), false);
  pending.resolve(response({ workspace: fixture() }));
  await save;
  assert.equal(parent.saving.stock, false);
  parent.saving.detail = true;
  assert.equal(state.beginStockMutation(), false);
  assert.equal(state.workspaceStockBusy, false);
});

test("failed stock management write keeps editing credentials and does not reload", async (t) => {
  const state = stockSetup(t, async () =>
    response({ errors: { password: ["Invalid"] } }, 422)
  );
  const items = [{ id: 1, editing: true, password: "draft-secret" }];
  state.manageStockItems = items;
  state.focusManageStockError = () => {};
  state.retryManageStockLoad = () =>
    assert.fail("failed save must preserve manager drafts");
  await state.submitWorkspaceStockForm({ action: "/stock-items/1" });
  assert.equal(state.manageStockItems, items);
  assert.equal(state.manageStockItems[0].password, "draft-secret");
  assert.equal(state.manageStockErrors.password[0], "Invalid");
  assert.equal(state.workspaceStockBusy, false);
});

test("policy and SKU watchers invalidate already displayed previews without fetching", () => {
  const state = create(() => assert.fail("invalidation is local"));
  const watchers = {};
  state.$watch = (key, callback) => {
    watchers[key] = callback;
  };
  state.init();
  for (const field of ["draft.policy", "previewCatalogId"]) {
    state.preview = { retail_idr: 1 };
    state.previewLoading = true;
    watchers[field]();
    assert.equal(state.preview, null);
    assert.equal(state.previewLoading, false);
  }
  state.destroy();
});

test("catalog debounce firing after close does not fetch or retain prior results", async () => {
  let calls = 0;
  const state = create(async () => {
    calls++;
    return response({
      items: [],
      pagination: { current_page: 1, last_page: 1 },
    });
  });
  state.openSupplierDialog();
  state.closeSupplierDialog();
  await state.searchCatalog();
  assert.equal(calls, 1);
  assert.deepEqual(state.catalog, []);
});

test("stock refresh retains a dirty family draft's original conflict token", async () => {
  let body;
  const state = create(async (_url, options) => {
    body = JSON.parse(options.body);
    return response({ message: "Conflict" }, 409);
  });
  state.draft.family.family_option_label = "Draft label";
  const remote = fixture();
  remote.product.family_option_label = "Remote label";
  remote.versions.family = "family-2";
  await state.acceptStockSaved({ workspace: remote });
  assert.equal(state.workspace.versions.family, "family-1");
  assert.equal(state.draft.family.family_option_label, "Draft label");
  assert.equal(await state.save("family"), false);
  assert.equal(body.workspace_version, "family-1");
  assert.equal(state.draft.family.family_option_label, "Draft label");
});

test("explicit family reload accepts current token without discarding other drafts", async (t) => {
  const original = globalThis.confirm;
  t.after(() => {
    globalThis.confirm = original;
  });
  globalThis.confirm = () => true;
  const remote = fixture();
  remote.product.family_option_label = "Remote label";
  remote.versions.family = "family-2";
  const state = create(async () => response(remote));
  state.draft.family.family_option_label = "Old draft";
  state.draft.detail.name = "Keep name";
  await state.reloadForm("family");
  assert.equal(state.workspace.versions.family, "family-2");
  assert.equal(state.draft.family.family_option_label, "Remote label");
  assert.equal(state.draft.detail.name, "Keep name");
});

test("structured stock login redirect retains credentials instead of reporting a save", async (t) => {
  const originalFetch = globalThis.fetch;
  t.after(() => {
    globalThis.fetch = originalFetch;
  });
  globalThis.fetch = async () => ({ ...response({}), redirected: true });
  const state = stockSetup(t, () => assert.fail("shared workflow uses fetch"));
  state.accountCards = [
    {
      email: "private@example.test",
      password: "draft-secret",
      accessMethod: "none",
      accessValue: "",
    },
  ];
  state.accountInputStage = "review";
  state.accountInputReviewed = true;
  state.accountInputReview = { status: "ready" };
  state.accountInputOperationKey = "keep-operation";
  state.stockModalOpen = true;
  state.stockAccountsFormAction = "/accounts";
  await state.submitAccountInput({ preventDefault() {} });
  assert.equal(state.stockModalOpen, true);
  assert.equal(state.accountCards[0].password, "draft-secret");
  assert.equal(state.accountInputOperationKey, "keep-operation");
  assert.equal(state.accountInputSubmitting, false);
  assert.equal(state.workspaceStockBusy, false);
  assert.equal(
    state.events.some((event) => event.name === "workspace-stock-saved"),
    false
  );
});

test("attach rejects a loaded row when filters change before the query watcher or debounce runs", async () => {
  for (const field of ["catalogQuery", "catalogSupplier"]) {
    let calls = 0;
    const state = create(async (url) => {
      calls++;
      assert.match(url, /^\/catalog\?/);
      return response({
        items: [{ id: 10, map_url: "/must-not-map", supplier_version: 1 }],
        pagination: { current_page: 1, last_page: 1 },
      });
    });
    await state.searchCatalog();
    state.catalogSelection = "10";
    state.equivalentConfirmed = true;
    state[field] = "changed";
    assert.equal(await state.attachSupplier(), false);
    assert.equal(calls, 1);
    assert.equal(state.catalogSelection, "");
    assert.equal(state.equivalentConfirmed, false);
    assert.deepEqual(state.catalog, []);
  }
});

test("catalog filter watchers immediately invalidate loaded choices without fetching", async () => {
  const state = create(async () =>
    response({
      items: [{ id: 10 }],
      pagination: { current_page: 1, last_page: 1 },
    })
  );
  const watchers = {};
  state.$watch = (key, callback) => {
    watchers[key] = callback;
  };
  state.init();
  for (const field of ["catalogQuery", "catalogSupplier"]) {
    await state.searchCatalog();
    state.catalogSelection = "10";
    state.equivalentConfirmed = true;
    state[field] = "changed";
    watchers[field]();
    assert.deepEqual(state.catalog, []);
    assert.equal(state.catalogSelection, "");
    assert.equal(state.equivalentConfirmed, false);
  }
  state.destroy();
});
