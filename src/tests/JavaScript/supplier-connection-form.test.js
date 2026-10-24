import assert from "node:assert/strict";
import test from "node:test";
import supplierConnectionForm, {
  rateFromDeposit,
} from "../../resources/js/supplier-connection-form.js";

const providers = {
  prodseller: { currency: "USDT", confirm: false, label: "ProdSeller" },
  nastele: { currency: "VND", confirm: false, label: "Nastele" },
  csxstore: { currency: "USD", confirm: false, label: "CSxStore" },
  canboso: { currency: "USD", confirm: false, label: "Canboso" },
  arrs: { currency: "USD", confirm: true, label: "ARRS" },
};

const rates = { USDT: "16000", USD: "17000", VND: "0.650001" };

function fixture(provider, overrides = {}) {
  const form = supplierConnectionForm(
    provider,
    providers,
    "/confirm",
    overrides.sample ?? null,
    overrides.rates ?? rates,
    overrides.currency ?? null,
    overrides.name ?? null
  );
  const watchers = new Map();
  form.$watch = (key, callback) => watchers.set(key, callback);
  form.init?.();

  return {
    form,
    select(next) {
      const previous = form.provider;
      form.provider = next;
      watchers.get("provider")?.(next, previous);
    },
  };
}

test("a fixed-currency connection shows the one global rate for its currency", () => {
  const { form } = fixture("nastele");

  assert.equal(form.currency, "VND");
  assert.equal(form.rateText, "Rp0,650001");
});

test("USD and USDT are separate global rates", () => {
  assert.equal(fixture("csxstore").form.currency, "USD");
  assert.equal(fixture("csxstore").form.rateText, "Rp17.000");
  assert.equal(fixture("prodseller").form.currency, "USDT");
  assert.equal(fixture("prodseller").form.rateText, "Rp16.000");
});

test("a fixed-currency connection ignores a submitted account currency", () => {
  const { form } = fixture("nastele", { currency: "USD" });

  assert.equal(form.currency, "VND");
  assert.equal(form.rateText, "Rp0,650001");
});

test("an account-selected provider uses the chosen currency rate", () => {
  const { form } = fixture("arrs");

  assert.equal(form.currency, "USD");
  assert.equal(form.rateText, "Rp17.000");
  form.accountCurrency = "USDT";
  assert.equal(form.currency, "USDT");
  assert.equal(form.rateText, "Rp16.000");
});

test("changing provider resets the account currency to that provider default", () => {
  const { form, select } = fixture("prodseller");
  select("nastele");

  assert.equal(form.currency, "VND");
  assert.equal(form.rateText, "Rp0,650001");
});

test("the supplier name follows the provider until edited and can be reset", () => {
  const { form, select } = fixture("nastele");
  assert.equal(form.name, "Nastele");

  select("prodseller");
  assert.equal(form.name, "ProdSeller");

  form.name = "Dompet VND";
  select("nastele");
  assert.equal(form.name, "Dompet VND");

  form.resetName();
  assert.equal(form.name, "Nastele");
});

test("an existing custom supplier name survives a provider change", () => {
  const { form, select } = fixture("nastele", { name: "Sumber utama" });
  assert.equal(form.name, "Sumber utama");

  select("prodseller");
  assert.equal(form.name, "Sumber utama");
});

test("a missing global rate is visible before saving", () => {
  const { form } = fixture("nastele", { rates: {} });

  assert.equal(form.rateText, "belum diatur");
});

test("the sample cost is shown only when the server sample belongs to this currency", () => {
  const { form } = fixture("nastele", {
    sample: { unit: "69000", currency: "VND", cost_idr: 44851 },
  });
  assert.equal(form.sampleText, "SKU 69.000 VND ≈ Rp44.851");

  const mismatch = fixture("nastele", {
    sample: { unit: "69000", currency: "USD", cost_idr: 44851 },
  });
  assert.equal(mismatch.form.sampleText, "");

  const missingCost = fixture("nastele", {
    sample: { unit: "69000", currency: "VND", cost_idr: null },
  });
  assert.equal(missingCost.form.sampleText, "");
});

test("the deposit helper turns what one USDT costs and credits into a rate", () => {
  // Rp18.000 per USDT and 24.000 VND credited per USDT -> Rp0,75 per VND.
  assert.equal(rateFromDeposit(18000, "24.000"), "0.75");
  assert.equal(rateFromDeposit("18.000", "24000"), "0.75");
  assert.equal(rateFromDeposit("18000", 24042), "0.74869");
  // The rate is always rounded up, so the stored unit cost can never be understated.
  assert.equal(rateFromDeposit(18000, 70000), "0.257143");
});

test("the deposit helper refuses incomplete or nonsensical input", () => {
  for (const args of [
    [18000, ""],
    [0, 24000],
    ["abc", 24000],
    [18000, 0],
    [null, 24000],
    [-18000, 24000],
  ]) {
    assert.throws(() => rateFromDeposit(...args), /lebih dari nol/);
  }
});
