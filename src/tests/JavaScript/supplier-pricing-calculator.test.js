import assert from "node:assert/strict";
import test from "node:test";
import supplierPricingCalculator, {
  basisPointsToPercent,
  formatIdr,
  formatPercent,
  percentToBasisPoints,
} from "../../resources/js/admin/suppliers/pricing-calculator.js";

const makeCalculator = (overrides = {}) =>
  supplierPricingCalculator({
    previewUrl: "/admin/products/1/supplier-pricing/preview",
    csrfToken: "csrf-token",
    initial: {
      mode: "manual",
      markup_basis_points: 0,
      profit_idr: 0,
      minimum_profit_idr: 0,
      buffer_idr: 0,
      rounding_idr: 1,
      version: 0,
      catalog_item_id: 7,
      current_retail_idr: 30000,
      preview: null,
    },
    ...overrides,
  });

const previewResponse = {
  cost_idr: 20000,
  retail_idr: 24000,
  profit_idr: 4000,
  profit_percent_basis_points: 2000,
  tier_schedule_valid: true,
};

test("percent conversions round to the documented basis points", () => {
  assert.equal(percentToBasisPoints(20), 2000);
  assert.equal(percentToBasisPoints(12.5), 1250);
  assert.equal(percentToBasisPoints(0), 0);
  assert.equal(percentToBasisPoints(-3), 0);
  assert.equal(percentToBasisPoints(2000), 100000);
  assert.equal(basisPointsToPercent(2000), 20);
  assert.equal(basisPointsToPercent(1250), 12.5);
  assert.equal(basisPointsToPercent(0), 0);
});

test("money and percent formatting stays in Indonesian retail format", () => {
  assert.equal(formatIdr(24000), "Rp24.000");
  assert.equal(formatIdr(null), "—");
  assert.equal(formatIdr(undefined), "—");
  assert.equal(formatPercent(2000), "20%");
  assert.equal(formatPercent(1250), "12,5%");
  assert.equal(formatPercent(null), "—");
});

test("manual mode never calls the preview endpoint", async () => {
  let calls = 0;
  const calculator = makeCalculator({
    fetchRequest: async () => {
      calls += 1;
      return { ok: true, json: async () => previewResponse };
    },
  });

  calculator.changed();
  assert.equal(calls, 0);
  assert.equal(calculator.preview, null);
  assert.equal(calculator.previewError, "");
});

test("automatic mode posts the percent as basis points and stores the preview", async () => {
  let request;
  const calculator = makeCalculator({
    fetchRequest: async (url, options) => {
      request = { url, options };
      return { ok: true, json: async () => previewResponse };
    },
  });

  calculator.mode = "percent";
  calculator.percent = 20;
  await calculator.loadPreview();

  assert.equal(request.url, "/admin/products/1/supplier-pricing/preview");
  assert.equal(request.options.headers["X-CSRF-TOKEN"], "csrf-token");
  const body = JSON.parse(request.options.body);
  assert.equal(body.mode, "percent");
  assert.equal(body.markup_basis_points, 2000);
  assert.equal(body.catalog_item_id, 7);
  assert.deepEqual(calculator.preview, previewResponse);
});

test("a failed preview keeps the operator informed without crashing", async () => {
  const calculator = makeCalculator({
    fetchRequest: async () => ({
      ok: false,
      json: async () => ({ message: "Pemetaan atau kebijakan tidak valid." }),
    }),
  });

  calculator.mode = "fixed";
  await calculator.loadPreview();

  assert.equal(calculator.preview, null);
  assert.match(calculator.previewError, /tidak valid/);
});

test("automatic mode without a mapped SKU asks for one instead of fetching", async () => {
  let calls = 0;
  const calculator = makeCalculator({
    initial: {
      mode: "percent",
      markup_basis_points: 2000,
      catalog_item_id: null,
      current_retail_idr: 30000,
    },
    fetchRequest: async () => {
      calls += 1;
      return { ok: true, json: async () => previewResponse };
    },
  });

  calculator.changed();
  assert.equal(calls, 0);
  assert.match(calculator.previewError, /SKU supplier/);
});

test("slower preview responses cannot overwrite newer ones", async () => {
  const first = {
    promise: null,
    resolve: null,
  };
  first.promise = new Promise((resolve) => {
    first.resolve = resolve;
  });
  const second = {
    promise: null,
    resolve: null,
  };
  second.promise = new Promise((resolve) => {
    second.resolve = resolve;
  });
  let call = 0;
  const calculator = makeCalculator({
    fetchRequest: () => (++call === 1 ? first.promise : second.promise),
  });

  calculator.mode = "percent";
  const stale = calculator.loadPreview();
  const current = calculator.loadPreview();

  first.resolve({
    ok: true,
    json: async () => ({ ...previewResponse, retail_idr: 99000 }),
  });
  await stale;
  assert.equal(calculator.preview, null);

  second.resolve({ ok: true, json: async () => previewResponse });
  await current;
  assert.equal(calculator.preview.retail_idr, 24000);
});
