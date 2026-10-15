import assert from "node:assert/strict";
import test from "node:test";
import supplierConnectionForm, {
  rateFromDeposit,
} from "../../resources/js/supplier-connection-form.js";

const providers = {
  prodseller: { currency: "USDT", confirm: false, band: null },
  nastele: {
    currency: "VND",
    confirm: false,
    band: { min: "0.1", max: "0.999999" },
  },
  csxstore: { currency: "USD", confirm: false, band: null },
  canboso: { currency: "USD", confirm: false, band: null },
  arrs: { currency: "USD", confirm: true, band: null },
  buymail: { currency: "IDR", confirm: false, band: null },
};

function fixture(provider, rate, sample = null) {
  const form = supplierConnectionForm(provider, providers, "/confirm", sample);
  const watchers = new Map();
  form.$refs = { exchangeRate: { value: rate } };
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

test("a new connection is never prefilled with a dollar rate", () => {
  const { form } = fixture("nastele", "");

  assert.equal(form.$refs.exchangeRate.value, "");
  assert.equal(form.rateDerivation, "");
  assert.equal(form.sampleDerivation, "");
  assert.equal(form.rateWarning, "");
});

test("a USDT rate cannot carry into the Nastele VND wallet or back", () => {
  const { form, select } = fixture("prodseller", "18000");
  select("nastele");
  assert.equal(form.$refs.exchangeRate.value, "");
  form.$refs.exchangeRate.value = "0.75";
  select("prodseller");
  assert.equal(form.$refs.exchangeRate.value, "");
});

test("opening an existing fractional rate preserves it until its unit changes", () => {
  const { form, select } = fixture("nastele", "0.750000", {
    unit: "69000",
    currency: "VND",
  });
  assert.equal(form.$refs.exchangeRate.value, "0.750000");
  assert.equal(form.rateDerivation, "1 VND ≈ Rp0,75");
  assert.equal(form.sampleDerivation, "SKU 69.000 VND ≈ Rp51.750");
  select("nastele");
  assert.equal(form.$refs.exchangeRate.value, "0.750000");
});

test("same fixed currency retains its rate but account-selected currency requires re-entry", () => {
  const { form, select } = fixture("csxstore", "18000");
  select("canboso");
  assert.equal(form.$refs.exchangeRate.value, "18000");
  select("arrs");
  assert.equal(form.$refs.exchangeRate.value, "");
});

test("leaving the fixed IDR wallet never carries its unit rate into VND", () => {
  const { form, select } = fixture("nastele", "0.75");
  select("buymail");
  assert.equal(form.$refs.exchangeRate.value, "1");
  select("nastele");
  assert.equal(form.$refs.exchangeRate.value, "");
});

test("the form derives the IDR value of one VND and of the most expensive mapped SKU", () => {
  const { form } = fixture("nastele", "0.692308", {
    unit: "69000",
    currency: "VND",
  });

  assert.equal(form.rateDerivation, "1 VND ≈ Rp0,692308");
  assert.equal(form.sampleDerivation, "SKU 69.000 VND ≈ Rp47.770");
  assert.equal(form.rateWarning, "");
});

test("a rupiah-per-USDT rate is shown as implausible before the server rejects it", () => {
  const { form } = fixture("nastele", "18000", {
    unit: "69000",
    currency: "VND",
  });

  assert.equal(form.rateDerivation, "1 VND ≈ Rp18.000");
  assert.equal(form.sampleDerivation, "SKU 69.000 VND ≈ Rp1.242.000.000");
  assert.match(form.rateWarning, /luar rentang wajar/);
  assert.match(form.rateWarning, /Rp0,999999/);
});

test("both edges of the plausibility band are warned about", () => {
  for (const rate of ["0.05", "1"]) {
    const { form } = fixture("nastele", rate);
    assert.match(form.rateWarning, /luar rentang wajar/, rate);
  }
  const { form } = fixture("nastele", "0.1");
  assert.equal(form.rateWarning, "");
  assert.equal(form.rateDerivation, "1 VND ≈ Rp0,1");
});

test("a wallet without a band is never warned about", () => {
  const { form } = fixture("csxstore", "0.692308");

  assert.equal(form.rateWarning, "");
  assert.equal(form.rateDerivation, "1 USD ≈ Rp0,692308");
  assert.equal(form.sampleDerivation, "");
});

test("the derivation follows the wallet when the connection changes currency", () => {
  const { form, select } = fixture("nastele", "0.692308", {
    unit: "69000",
    currency: "VND",
  });
  select("prodseller");

  assert.equal(form.rateDerivation, "");
  assert.equal(form.sampleDerivation, "");
  form.$refs.exchangeRate.value = "16000";
  form.refreshRateDerivation();
  assert.equal(form.rateDerivation, "1 USDT ≈ Rp16.000");
  assert.equal(form.rateWarning, "");
});

test("a saved sample from another currency is never shown for the typed wallet", () => {
  const { form } = fixture("nastele", "0.692308", {
    unit: "69000",
    currency: "USD",
  });

  assert.equal(form.sampleDerivation, "");
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

test("the calculator never fills the rate on its own and applies only on request", () => {
  const { form } = fixture("nastele", "", { unit: "69000", currency: "VND" });

  assert.equal(form.$refs.exchangeRate.value, "");
  form.calcIdrPerUsdt = "18.000";
  form.calcVndPerUsdt = "24.000";
  form.refreshRateCalculator();

  assert.equal(form.calcSuggestion, "0.75");
  assert.equal(form.calcSample, "SKU 69.000 VND");
  assert.equal(form.calcCost, "Rp51.750");
  assert.equal(form.calcWarning, "");
  assert.equal(form.calcError, "");
  assert.equal(
    form.$refs.exchangeRate.value,
    "",
    "typing into the helper must never prefill the stored rate"
  );

  form.applyRateSuggestion();
  assert.equal(form.$refs.exchangeRate.value, "0.75");
  assert.equal(form.rateDerivation, "1 VND ≈ Rp0,75");
  assert.equal(form.sampleDerivation, "SKU 69.000 VND ≈ Rp51.750");
});

test("the calculator clears itself when a field is emptied and flags an out-of-band result", () => {
  const { form } = fixture("nastele", "", { unit: "69000", currency: "VND" });
  form.calcIdrPerUsdt = "18000";
  form.calcVndPerUsdt = "1000";
  form.refreshRateCalculator();

  assert.equal(form.calcSuggestion, "18");
  assert.match(form.calcWarning, /luar rentang wajar/);

  form.calcVndPerUsdt = "";
  form.refreshRateCalculator();

  assert.equal(form.calcSuggestion, "");
  assert.equal(form.calcSample, "");
  assert.equal(form.calcCost, "");
  assert.equal(form.calcWarning, "");
  assert.equal(form.calcError, "");
});
