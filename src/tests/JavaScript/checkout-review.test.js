import assert from "node:assert/strict";
import test from "node:test";
import checkoutReview from "../../resources/js/storefront/checkout-review.js";

function reviewConfig(overrides = {}) {
  return {
    quantityEditable: true,
    quantity: 1,
    minimumQuantity: 1,
    maximumQuantity: 10,
    baseIdrPrice: 10_000,
    baseUsdtPrice: 1_000_000,
    tiers: [],
    copy: {
      unavailable: "Belum tersedia",
      tier: "Harga grosir diterapkan mulai :count unit.",
    },
    ...overrides,
  };
}

test("quantity review switches prices at each reached tier and restores them below it", () => {
  const review = checkoutReview(
    reviewConfig({
      tiers: [
        {
          minimum_quantity: 2,
          price_amount: 9_000,
          usdt_price_amount: 900_000,
        },
        {
          minimum_quantity: 4,
          price_amount: 8_000,
          usdt_price_amount: 800_000,
        },
      ],
    })
  );

  assert.equal(review.quote.idrUnitPrice, 10_000);
  assert.equal(review.quote.idrSubtotal, 10_000);
  assert.equal(review.tierNote, "");

  review.quantity = 2;
  assert.equal(review.quote.idrUnitPrice, 9_000);
  assert.equal(review.quote.idrSubtotal, 18_000);
  assert.equal(review.quote.usdtSubtotal, 1_800_000);
  assert.equal(review.tierNote, "Harga grosir diterapkan mulai 2 unit.");

  review.quantity = 3;
  assert.equal(review.quote.appliedTierMinimum, 2);
  assert.equal(review.quote.idrSubtotal, 27_000);

  review.quantity = 4;
  assert.equal(review.quote.idrUnitPrice, 8_000);
  assert.equal(review.quote.appliedTierMinimum, 4);
  assert.equal(review.quote.idrSubtotal, 32_000);

  review.quantity = 3;
  assert.equal(review.quote.idrUnitPrice, 9_000);
  assert.equal(review.quote.appliedTierMinimum, 2);
});

test("base prices remain the quote when no tier applies or USDT is unavailable", () => {
  const review = checkoutReview(
    reviewConfig({
      quantity: 3,
      baseIdrPrice: 7_500,
      baseUsdtPrice: null,
      tiers: [],
      items: [
        {
          productId: "17",
          quantity: 3,
          idrUnitPrice: 7_500,
          usdtUnitPrice: null,
          idrSubtotal: 22_500,
          tierMinimum: null,
        },
      ],
    })
  );

  assert.deepEqual(review.quote, {
    quantity: 3,
    idrUnitPrice: 7_500,
    idrSubtotal: 22_500,
    usdtUnitPrice: null,
    usdtSubtotal: null,
    appliedTierMinimum: null,
  });
  assert.equal(review.subtotalIdr, "Rp\u00a022.500");
  assert.equal(review.subtotalUsdt, "Belum tersedia");
  assert.equal(review.tierNote, "");
});

test("invalid tier schedules reject the whole preview instead of accepting a partial quote", () => {
  const cases = [
    {
      name: "unsorted thresholds",
      tiers: [
        {
          minimum_quantity: 4,
          price_amount: 8_000,
          usdt_price_amount: 800_000,
        },
        {
          minimum_quantity: 2,
          price_amount: 9_000,
          usdt_price_amount: 900_000,
        },
      ],
    },
    {
      name: "duplicate threshold",
      tiers: [
        {
          minimum_quantity: 2,
          price_amount: 9_000,
          usdt_price_amount: 900_000,
        },
        {
          minimum_quantity: 2,
          price_amount: 8_000,
          usdt_price_amount: 800_000,
        },
      ],
    },
    {
      name: "non-decreasing IDR price",
      tiers: [
        {
          minimum_quantity: 2,
          price_amount: 10_000,
          usdt_price_amount: 900_000,
        },
      ],
    },
    {
      name: "missing paired USDT price",
      tiers: [
        { minimum_quantity: 2, price_amount: 9_000, usdt_price_amount: null },
      ],
    },
    {
      name: "unsafe tier amount",
      tiers: [
        {
          minimum_quantity: 2,
          price_amount: "9007199254740992",
          usdt_price_amount: 900_000,
        },
      ],
    },
    {
      name: "more than five tiers",
      tiers: Array.from({ length: 6 }, (_, index) => ({
        minimum_quantity: index + 2,
        price_amount: 9_000 - index * 100,
        usdt_price_amount: 900_000 - index * 10_000,
      })),
    },
  ];

  for (const invalidSchedule of cases) {
    const review = checkoutReview(
      reviewConfig({ tiers: invalidSchedule.tiers })
    );
    assert.equal(review.quote, null, invalidSchedule.name);
    assert.equal(review.subtotalIdr, "Belum tersedia", invalidSchedule.name);
  }
});

test("quantity bounds, step controls, and unsafe arithmetic reject false quotes", () => {
  const outsideMaximum = checkoutReview(
    reviewConfig({ quantity: 11, maximumQuantity: 10 })
  );
  assert.equal(outsideMaximum.quote, null);
  assert.equal(outsideMaximum.subtotalIdr, "Belum tersedia");

  const steps = checkoutReview(
    reviewConfig({ quantity: 1, maximumQuantity: 3 })
  );
  steps.stepQuantity(1);
  assert.equal(steps.quantity, 2);
  assert.equal(steps.quote.idrSubtotal, 20_000);
  steps.stepQuantity(100);
  assert.equal(steps.quantity, 3);
  steps.stepQuantity(-100);
  assert.equal(steps.quantity, 1);

  const idrOverflow = checkoutReview(
    reviewConfig({
      quantity: 2,
      baseIdrPrice: Number.MAX_SAFE_INTEGER,
      baseUsdtPrice: null,
    })
  );
  assert.equal(idrOverflow.quote, null);
  assert.equal(idrOverflow.subtotalIdr, "Belum tersedia");

  const usdtOverflow = checkoutReview(
    reviewConfig({
      quantity: 2,
      baseIdrPrice: 1,
      baseUsdtPrice: Number.MAX_SAFE_INTEGER,
    })
  );
  assert.equal(usdtOverflow.quote, null);
  assert.equal(usdtOverflow.subtotalUsdt, "Belum tersedia");

  const invalidUsdtBase = checkoutReview(reviewConfig({ baseUsdtPrice: 0 }));
  assert.equal(invalidUsdtBase.quote, null);
});
