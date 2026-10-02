import assert from "node:assert/strict";
import test from "node:test";
import supplierSellFlow, {
  suggestPrice,
} from "../../resources/js/admin/suppliers/sell-flow.js";

const makeFlow = (overrides = {}) => {
  const flow = supplierSellFlow({
    searchUrl: "/admin/product-search",
    ...overrides,
  });
  flow.$nextTick = (callback) => callback();
  return flow;
};

const product = (id, name, overrides = {}) => ({
  id,
  name,
  price: "Rp25.000",
  price_amount: 25000,
  is_active: true,
  ...overrides,
});

const deferred = () => {
  let resolve;
  const promise = new Promise((resolvePromise) => {
    resolve = resolvePromise;
  });
  return { promise, resolve };
};

test("suggestPrice rounds cost plus markup up to the nearest thousand", () => {
  assert.equal(suggestPrice(20000), "24000");
  assert.equal(suggestPrice(20100), "25000");
  assert.equal(suggestPrice(null), "");
  assert.equal(suggestPrice(0), "");
  assert.equal(suggestPrice(-5), "");
});

test("openFor prefills the create-new draft from the catalog item", () => {
  const flow = makeFlow();
  flow.openFor({
    name: "Netflix Bulan",
    cost_idr: 20000,
    suggested_price: 24000,
    sellable: true,
    import_url: "/admin/suppliers/catalog/1/import",
    map_url: "/admin/suppliers/catalog/1/map",
  });

  assert.equal(flow.open, true);
  assert.equal(flow.mode, "new");
  assert.equal(flow.name, "Netflix Bulan");
  assert.equal(flow.price, "24000");
  assert.equal(flow.selected, null);
  assert.equal(flow.equivalentConfirmed, false);
});

test("openFor falls back to the local suggestion when the server sends none", () => {
  const flow = makeFlow();
  flow.openFor({ name: "Spotify", cost_idr: 10000, suggested_price: null });
  assert.equal(flow.price, "12000");
});

test("close restores the opener but is ignored while submitting", () => {
  const focused = [];
  const opener = { isConnected: true, focus: () => focused.push("opener") };
  const previousDocument = globalThis.document;
  globalThis.document = { activeElement: opener };

  try {
    const flow = makeFlow();
    flow.openFor({ name: "Netflix" });
    flow.submitting = true;
    flow.close();
    assert.equal(flow.open, true);

    flow.submitting = false;
    flow.close();
    assert.equal(flow.open, false);
    assert.deepEqual(focused, ["opener"]);
  } finally {
    globalThis.document = previousDocument;
  }
});

test("search requires two characters and stores bounded results", async () => {
  const calls = [];
  const flow = makeFlow({
    fetchRequest: async (url) => {
      calls.push(url);
      return {
        ok: true,
        json: async () => ({ products: [product(9, "Netflix Premium")] }),
      };
    },
  });

  flow.search = "N";
  await flow.runSearch();
  assert.deepEqual(flow.results, []);
  assert.equal(calls.length, 0);

  flow.search = "Netflix";
  await flow.runSearch();
  assert.equal(calls.length, 1);
  assert.match(calls[0], /q=Netflix/);
  assert.equal(flow.results[0].id, 9);
  assert.equal(flow.searching, false);
});

test("a stale search response cannot replace newer results", async () => {
  const first = deferred();
  const second = deferred();
  let call = 0;
  const flow = makeFlow({
    fetchRequest: () => (++call === 1 ? first.promise : second.promise),
  });

  flow.search = "Netflix";
  const stale = flow.runSearch();
  flow.search = "Spotify";
  const current = flow.runSearch();

  first.resolve({
    ok: true,
    json: async () => ({ products: [product(1, "Netflix Premium")] }),
  });
  await stale;
  assert.deepEqual(flow.results, []);

  second.resolve({
    ok: true,
    json: async () => ({ products: [product(2, "Spotify Family")] }),
  });
  await current;
  assert.equal(flow.results[0].name, "Spotify Family");
});

test("copy search requests descriptions and fills the draft", async () => {
  const calls = [];
  const flow = makeFlow({
    fetchRequest: async (url) => {
      calls.push(url);
      return {
        ok: true,
        json: async () => ({
          products: [
            { ...product(3, "Produk Lama"), description: "Deskripsi lama." },
          ],
        }),
      };
    },
  });

  flow.openFor({ name: "Netflix" });
  flow.copySearch = "Produk";
  await flow.runCopySearch();

  assert.equal(calls.length, 1);
  assert.match(calls[0], /include=description/);
  assert.equal(flow.copyResults[0].description, "Deskripsi lama.");

  flow.applyCopy(flow.copyResults[0]);
  assert.equal(flow.description, "Deskripsi lama.");
  assert.equal(flow.copiedFrom, "Produk Lama");
  assert.deepEqual(flow.copyResults, []);
  assert.equal(flow.copySearch, "");
});

test("copy search ignores queries shorter than two characters", async () => {
  let calls = 0;
  const flow = makeFlow({
    fetchRequest: async () => {
      calls += 1;
      return { ok: true, json: async () => ({ products: [] }) };
    },
  });

  flow.copySearch = "a";
  await flow.runCopySearch();
  assert.equal(calls, 0);
  assert.deepEqual(flow.copyResults, []);
});

test("openFor resets the previously copied description marker", () => {
  const flow = makeFlow();
  flow.openFor({ name: "Produk A" });
  flow.applyCopy({ name: "Produk Lama", description: "Deskripsi lama." });
  assert.equal(flow.copiedFrom, "Produk Lama");

  flow.openFor({ name: "Produk B" });
  assert.equal(flow.copiedFrom, null);
  assert.equal(flow.description, "");
});

test("selecting a product clears results and requires explicit equivalence", () => {
  const flow = makeFlow();
  flow.results = [product(1, "Netflix Premium")];
  flow.selectProduct(product(1, "Netflix Premium"));

  assert.equal(flow.selected.id, 1);
  assert.deepEqual(flow.results, []);
  assert.equal(flow.canSubmitLink(), false);

  flow.equivalentConfirmed = true;
  assert.equal(flow.canSubmitLink(), true);

  flow.clearSelected();
  assert.equal(flow.selected, null);
  assert.equal(flow.canSubmitLink(), false);
});

test("markSubmitting locks the modal until the browser navigates", () => {
  const flow = makeFlow();
  flow.openFor({ name: "Netflix" });
  flow.markSubmitting();
  assert.equal(flow.submitting, true);
});

test("keepModalFocus pulls focus back into the dialog", () => {
  const focused = [];
  const first = {
    focus: () => focused.push("first"),
    getClientRects: () => [1],
  };
  const dialog = {
    getClientRects: () => [1],
    contains: () => false,
    querySelectorAll: () => [first],
  };
  const previousDocument = globalThis.document;
  globalThis.document = { getElementById: () => dialog };

  try {
    const flow = makeFlow();
    flow.keepModalFocus({ target: {} }, "supplier-sell-modal");
    assert.deepEqual(focused, ["first"]);
  } finally {
    globalThis.document = previousDocument;
  }
});
