import test from "node:test";
import assert from "node:assert/strict";
import { registerStorefrontComponents } from "../../resources/js/storefront/alpine-components.js";

test("Storefront registry registers each Alpine component factory", () => {
  const registrations = new Map();
  const alpine = {
    data(name, factory) {
      registrations.set(name, factory);
    },
  };

  registerStorefrontComponents(alpine);

  const names = ["checkoutReview", "familyQuickBuy", "mobileMenu"];
  assert.deepEqual([...registrations.keys()].sort(), names.sort());
  for (const name of names) {
    assert.equal(typeof registrations.get(name), "function");
  }
});
