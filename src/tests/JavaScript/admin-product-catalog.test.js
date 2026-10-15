import assert from "node:assert/strict";
import test from "node:test";
import productCatalog from "../../resources/js/admin-dashboard/product-catalog.js";

const sortLabels = {
  name_asc: "Nama A–Z",
  name_desc: "Nama Z–A",
  members_desc: "Jumlah produk terbanyak",
};
const group = (id, index, memberCount = 1) => ({
  id,
  name: `Family ${id}`,
  search: [`family ${id}`],
  ranks: { name_asc: index, name_desc: 100 - index, members_desc: index },
  members: Array.from({ length: memberCount }, (_, member) => ({
    id: id * 100 + member,
    name: `Option ${member}`,
    search: [`option ${id}-${member}`],
  })),
});

function setup(t, groups, extra = {}) {
  const original = {
    window: global.window,
    document: global.document,
    fetch: global.fetch,
  };
  const requests = [];
  const replacements = [];
  const listeners = new Map();
  global.window = {
    location: {
      href: "https://catalog.test/admin?tab=produk&source=telegram",
      origin: "https://catalog.test",
      hash: "",
    },
    history: {
      state: null,
      replaceState: (_state, _title, url) => replacements.push(String(url)),
    },
    addEventListener: (event, callback) => listeners.set(event, callback),
    removeEventListener: (event) => listeners.delete(event),
  };
  global.document = { getElementById: () => null };
  global.fetch = (url, options) =>
    new Promise((resolve, reject) =>
      requests.push({ url, options, resolve, reject })
    );
  t.after(() => Object.assign(global, original));
  const catalog = productCatalog({
    groups,
    sortLabels,
    cardsUrl: "/admin/products/cards",
    workspaceUrl: "https://catalog.test/admin/products/__PRODUCT__/workspace",
    ...extra,
  });
  catalog.$nextTick = (callback) => callback();
  catalog.init();
  return { catalog, requests, replacements, listeners };
}
const settle = async () => {
  await Promise.resolve();
  await Promise.resolve();
  await Promise.resolve();
};

test("search spans later original pages and puts matching family members in the first bounded detail page", (t) => {
  const groups = Array.from({ length: 25 }, (_, index) =>
    group(index + 1, index, index === 24 ? 30 : 1)
  );
  const { catalog, requests, replacements } = setup(t, groups);
  assert.equal(catalog.pageGroups.length, 20);
  assert.equal(requests.length, 0);
  catalog.search = "  OPTION   25-29 ";
  catalog.filter();
  assert.equal(catalog.filteredGroups.length, 1);
  assert.equal(catalog.selectedGroupId, 25);
  assert.equal(catalog.orderedMembers(groups[24])[0].id, 2529);
  const ids = requests[0].url.searchParams.getAll("ids[]");
  assert.equal(ids.length, 12);
  assert.equal(ids[0], "2529");
  assert.match(replacements.at(-1), /source=telegram/);
  assert.match(replacements.at(-1), /product_search=option\+25-29/);
});

test("family matches retain every option and local sorts, pagination, reset and empty results never navigate", (t) => {
  const groups = Array.from({ length: 25 }, (_, index) =>
    group(index + 1, index)
  );
  const { catalog, requests, replacements } = setup(t, groups);
  catalog.sort = "name_desc";
  catalog.filter();
  assert.equal(catalog.pageGroups[0].id, 25);
  catalog.goToPage(2);
  assert.equal(catalog.pageGroups.length, 5);
  assert.equal(catalog.pageGroups[0].id, 5);
  assert.equal(requests.length, 0);
  catalog.search = "family 25";
  catalog.filter();
  assert.equal(catalog.page, 1);
  assert.deepEqual(catalog.orderedMembers(groups[24]), groups[24].members);
  catalog.search = "not present";
  catalog.filter();
  assert.equal(catalog.filteredGroups.length, 0);
  assert.equal(catalog.lastPage, 1);
  assert.equal(requests[0].options.signal.aborted, true);
  catalog.reset();
  assert.equal(catalog.search, "");
  assert.equal(catalog.sort, "name_asc");
  assert.equal(catalog.filteredGroups.length, 25);
  assert.equal(catalog.detailHtml, "");
  assert.equal(
    new URL(replacements.at(-1)).searchParams.has("product_search"),
    false
  );
});

test("late detail responses cannot restore closed or replaced family cards", async (t) => {
  const groups = [group(1, 0), group(2, 1)];
  const { catalog, requests } = setup(t, groups);
  catalog.openGroup(groups[0]);
  catalog.openGroup(groups[1]);
  assert.equal(requests[0].options.signal.aborted, true);
  requests[1].resolve({
    ok: true,
    json: async () => ({ html: "second family" }),
  });
  await settle();
  assert.equal(catalog.detailHtml, "second family");
  requests[0].resolve({
    ok: true,
    json: async () => ({ html: "stale first family" }),
  });
  await settle();
  assert.equal(catalog.detailHtml, "second family");
  catalog.toggleGroup(groups[1]);
  assert.equal(catalog.detailHtml, "");
  assert.equal(catalog.detailLoading, false);
});

test("initial product deep links reveal the correct group and bounded member page", (t) => {
  const groups = Array.from({ length: 25 }, (_, index) =>
    group(index + 1, index, index === 24 ? 30 : 1)
  );
  const { catalog, requests } = setup(t, groups);
  global.window.location.hash = "#product-2529";
  catalog.revealProductHash();
  assert.equal(catalog.page, 2);
  assert.equal(catalog.selectedGroupId, 25);
  assert.equal(catalog.memberPage, 3);
  assert.deepEqual(requests[0].url.searchParams.getAll("ids[]"), [
    "2524",
    "2525",
    "2526",
    "2527",
    "2528",
    "2529",
  ]);
  assert.equal(
    catalog.workspaceUrl(2529),
    "https://catalog.test/admin/products/2529/workspace"
  );
});

test("failed card loads expose a recoverable error without retaining previous cards", async (t) => {
  const groups = [group(1, 0)];
  const { catalog, requests, listeners } = setup(t, groups);
  catalog.openGroup(groups[0]);
  requests[0].resolve({ ok: false });
  await settle();
  assert.match(catalog.detailError, /Coba lagi/);
  assert.equal(catalog.detailLoading, false);
  assert.equal(catalog.detailHtml, "");
  const retry = catalog.loadDetails(groups[0]);
  requests[1].resolve({ ok: true, json: async () => ({ html: "recovered" }) });
  await retry;
  assert.equal(catalog.detailHtml, "recovered");
  assert.equal(catalog.detailError, "");
  catalog.destroy();
  assert.equal(listeners.size, 0);
  assert.equal(catalog.detailHtml, "");
});
