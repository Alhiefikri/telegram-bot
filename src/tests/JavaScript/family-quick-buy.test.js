import assert from "node:assert/strict";
import test from "node:test";
import familyQuickBuy from "../../resources/js/storefront/family-quick-buy.js";

function option(id, overrides = {}) {
  return {
    id,
    name: `Package ${id}`,
    priceLabel: `Rp ${id}`,
    stock: 0,
    selected: false,
    productUrl: `/products/${id}?return=%2Ffamilies%2Fcloud`,
    checkoutUrl: `/checkout/${id}?return=%2Ffamilies%2Fcloud&source=family`,
    ...overrides,
  };
}

test("initialization selects the first available option", () => {
  const component = familyQuickBuy([
    option("sold-out", { stock: 0 }),
    option("available", { stock: 2 }),
    option("later", { stock: 4 }),
  ]);

  assert.equal(component.selectedProductId, "available");
  assert.equal(component.selectedOption.name, "Package available");
  assert.equal(component.isAvailable, true);
});

test("initialization preserves the server-selected available option", () => {
  const component = familyQuickBuy([
    option("first", { stock: 3 }),
    option("selected", { stock: 2, selected: true }),
  ]);

  assert.equal(component.selectedProductId, "selected");
});

test("selecting another option updates its presentation and checkout URL", () => {
  const component = familyQuickBuy([
    option("one", { stock: 4, selected: true }),
    option("two", {
      stock: 3,
      priceLabel: "Rp 2.000",
      name: "Premium",
      checkoutUrl:
        "/checkout/two?quantity=9&return=%2Ffamilies%2Fcloud&source=family",
    }),
  ]);

  component.selectOption("two");
  component.setQuantity(2);

  assert.equal(component.selectedOption.name, "Premium");
  assert.equal(component.selectedOption.priceLabel, "Rp 2.000");
  assert.equal(component.isSelected("two"), true);
  assert.equal(component.isSelected("one"), false);
  assert.equal(component.announcement, "Premium dipilih");

  const checkoutUrl = new URL(
    component.checkoutHref,
    "https://storefront.example"
  );
  assert.equal(checkoutUrl.pathname, "/checkout/two");
  assert.equal(checkoutUrl.searchParams.get("return"), "/families/cloud");
  assert.equal(checkoutUrl.searchParams.get("source"), "family");
  assert.deepEqual(checkoutUrl.searchParams.getAll("quantity"), ["2"]);
});

test("quantity input and steps stay within one and the selected stock cap", () => {
  const component = familyQuickBuy([
    option("large-stock", { stock: 25, selected: true }),
    option("small-stock", { stock: 2 }),
  ]);

  assert.equal(component.quantityMaximum, 20);
  component.setQuantity(99);
  assert.equal(component.quantity, 20);
  component.adjustQuantity(1);
  assert.equal(component.quantity, 20);
  component.setQuantity(1.9);
  assert.equal(component.quantity, 1);
  component.adjustQuantity(-1);
  assert.equal(component.quantity, 1);
  component.setQuantity(8);
  component.selectOption("small-stock");
  assert.equal(component.quantityMaximum, 2);
  assert.equal(component.quantity, 2);
});

test("sold-out selection disables buying and points to that Product detail", () => {
  const component = familyQuickBuy([
    option("available", { stock: 3, selected: true }),
    option("sold-out", { stock: 0 }),
  ]);

  component.selectOption("sold-out");
  component.setQuantity(4);

  assert.equal(component.isAvailable, false);
  assert.equal(component.quantityMaximum, 0);
  assert.equal(component.quantity, 1);
  assert.equal(component.checkoutLabel, "Lihat detail opsi");
  assert.equal(component.checkoutAriaLabel, "Lihat detail Package sold-out");
  assert.equal(
    component.checkoutHref,
    "/products/sold-out?return=%2Ffamilies%2Fcloud"
  );
});

test("radio keyboard navigation wraps, selects, announces, and focuses options", () => {
  const component = familyQuickBuy([
    option("one", { stock: 1, selected: true }),
    option("two", { stock: 1 }),
    option("three", { stock: 0 }),
  ]);
  const focused = [];
  const refs = Object.fromEntries(
    component.options.map((candidate) => [
      `family-option-button-${candidate.id}`,
      { focus: () => focused.push(candidate.id) },
    ])
  );

  function keydown(key, productId) {
    let prevented = false;
    component.handleOptionKeydown(
      {
        key,
        preventDefault() {
          prevented = true;
        },
      },
      productId,
      refs
    );
    return prevented;
  }

  assert.equal(keydown("ArrowLeft", "one"), true);
  assert.equal(component.selectedProductId, "three");
  assert.deepEqual(focused, ["three"]);
  assert.equal(component.announcement, "Package three dipilih");

  assert.equal(keydown("ArrowRight", "three"), true);
  assert.equal(component.selectedProductId, "one");
  assert.equal(keydown("ArrowRight", "one"), true);
  assert.equal(component.selectedProductId, "two");
  assert.deepEqual(focused, ["three", "one", "two"]);
  assert.equal(keydown("Home", "two"), true);
  assert.equal(component.selectedProductId, "one");
  assert.equal(keydown("End", "one"), true);
  assert.equal(component.selectedProductId, "three");
  assert.equal(keydown(" ", "three"), true);
  assert.equal(component.selectedProductId, "three");
  assert.equal(keydown("Enter", "two"), true);
  assert.equal(component.selectedProductId, "two");
  assert.equal(keydown("Tab", "two"), false);
});
