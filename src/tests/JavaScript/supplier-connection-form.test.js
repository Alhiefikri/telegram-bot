import assert from "node:assert/strict";
import test from "node:test";
import supplierConnectionForm from "../../resources/js/supplier-connection-form.js";

const providers = {
  prodseller: { currency: "USDT", confirm: false },
  nastele: { currency: "VND", confirm: false },
  csxstore: { currency: "USD", confirm: false },
  canboso: { currency: "USD", confirm: false },
  arrs: { currency: "USD", confirm: true },
  buymail: { currency: "IDR", confirm: false },
};

function fixture(provider, rate) {
  const form = supplierConnectionForm(provider, providers, "/confirm");
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

test("a USDT rate cannot carry into the Nastele VND wallet or back", () => {
  const { form, select } = fixture("prodseller", "18000");
  select("nastele");
  assert.equal(form.$refs.exchangeRate.value, "");
  form.$refs.exchangeRate.value = "0.75";
  select("prodseller");
  assert.equal(form.$refs.exchangeRate.value, "");
});

test("opening an existing fractional rate preserves it until its unit changes", () => {
  const { form, select } = fixture("nastele", "0.750000");
  assert.equal(form.$refs.exchangeRate.value, "0.750000");
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
