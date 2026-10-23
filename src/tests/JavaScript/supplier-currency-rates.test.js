import assert from "node:assert/strict";
import test from "node:test";
import supplierCurrencyRates from "../../resources/js/supplier-currency-rates.js";

const bands = { VND: { min: "0.1", max: "0.999999" } };

function fixture() {
  const form = supplierCurrencyRates("/confirm", bands);
  const input = {
    value: "",
    dispatched: 0,
    dispatchEvent() {
      this.dispatched += 1;
    },
  };
  form.$refs = {
    rateForm: { querySelector: () => input },
    rateFormVnd: null,
  };

  return { form, input };
}

test("the VND helper derives the rate from actual IDR and credited VND", () => {
  const { form } = fixture();
  form.calcIdrPerUsdt = "18.000";
  form.calcVndPerUsdt = "24.000";
  form.refreshCalculator();

  assert.equal(form.calcSuggestion, "0.75");
  assert.equal(form.calcWarning, "");
  assert.equal(form.calcError, "");
});

test("the suggestion never writes itself into the VND rate field", () => {
  const { form, input } = fixture();
  form.calcIdrPerUsdt = "18000";
  form.calcVndPerUsdt = "24000";
  form.refreshCalculator();

  assert.equal(input.value, "");
  form.applyVndSuggestion();
  assert.equal(input.value, "0.75");
});

test("an out-of-band suggestion is flagged and can still be applied", () => {
  const { form, input } = fixture();
  form.calcIdrPerUsdt = "18000";
  form.calcVndPerUsdt = "1000";
  form.refreshCalculator();

  assert.equal(form.calcSuggestion, "18");
  assert.match(form.calcWarning, /luar rentang wajar/);
  form.applyVndSuggestion();
  assert.equal(input.value, "18");
});

test("emptying a calculator field clears the suggestion", () => {
  const { form } = fixture();
  form.calcIdrPerUsdt = "18000";
  form.calcVndPerUsdt = "24000";
  form.refreshCalculator();
  form.calcVndPerUsdt = "";
  form.refreshCalculator();

  assert.equal(form.calcSuggestion, "");
  assert.equal(form.calcWarning, "");
  assert.equal(form.calcError, "");
});

test("invalid calculator input reports a safe error", () => {
  const { form } = fixture();
  form.calcIdrPerUsdt = "abc";
  form.calcVndPerUsdt = "24000";
  form.refreshCalculator();

  assert.equal(form.calcSuggestion, "");
  assert.match(form.calcError, /lebih dari nol/);
});
