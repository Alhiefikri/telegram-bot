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
    items: [
      {
        productId: "17",
        quantity: 1,
        idrUnitPrice: 10_000,
        usdtUnitPrice: 1_000_000,
        idrSubtotal: 10_000,
        tierMinimum: null,
      },
    ],
    initialIdrSubtotal: 10_000,
    initialUsdtSubtotal: 1_000_000,
    initialItemCount: 1,
    canSubmit: true,
    selectedPaymentMethod: "qris",
    paymentOptions: [
      {
        value: "qris",
        label: "QRIS",
        description: "Bayar melalui invoice QRIS.",
        provider: "klikqris",
        amountKind: "idr",
        amountLabel: "Subtotal review (IDR)",
        amountHelp: "Total final pada invoice.",
        submitLabel: "Buat invoice QRIS",
      },
      {
        value: "binance_pay_id",
        label: "Binance Pay ID",
        description: "Bayar melalui Binance Pay.",
        provider: "binance",
        amountKind: "usdt",
        amountLabel: "Total dasar USDT / estimasi",
        amountHelp: "Nominal final pada invoice.",
        submitLabel: "Buat invoice Binance Pay",
      },
      {
        value: "sakurupiah",
        label: "QRIS Sakurupiah",
        description: "Bayar melalui Sakurupiah.",
        provider: "sakurupiah",
        amountKind: "idr",
        amountLabel: "Subtotal review (IDR)",
        amountHelp: "Total final pada invoice.",
        submitLabel: "Buat invoice Sakurupiah",
      },
    ],
    stickyPaymentLabel: "Metode pembayaran belum tersedia",
    stickyAmountLabel: "Total review (IDR)",
    stickySubmitLabel: "Lanjut bayar",
    contactDefaultHelp: "Kontak bantuan bersifat opsional.",
    contactDisclosureOpen: false,
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
  assert.equal(review.itemTierNote("17"), "");

  review.quantity = 2;
  assert.equal(review.quote.idrUnitPrice, 9_000);
  assert.equal(review.quote.idrSubtotal, 18_000);
  assert.equal(review.quote.usdtSubtotal, 1_800_000);
  assert.equal(
    review.itemTierNote("17"),
    "Harga grosir diterapkan mulai 2 unit."
  );

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
  assert.equal(review.itemIdrUnitPrice("17"), "Rp\u00a07.500");
  assert.equal(review.itemUsdtUnitPrice("17"), "—");
  assert.equal(review.itemHasUsdtPrice("17"), false);
  assert.equal(review.itemTierNote("17"), "");
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
    assert.equal(review.currentSubtotalIdr, null, invalidSchedule.name);
  }
});

test("quantity bounds, step controls, and unsafe arithmetic reject false quotes", () => {
  const outsideMaximum = checkoutReview(
    reviewConfig({ quantity: 11, maximumQuantity: 10 })
  );
  assert.equal(outsideMaximum.quote, null);
  assert.equal(outsideMaximum.summaryItemCount, "—");

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
  assert.equal(idrOverflow.orderSubtotalIdrText, "—");

  const usdtOverflow = checkoutReview(
    reviewConfig({
      quantity: 2,
      baseIdrPrice: 1,
      baseUsdtPrice: Number.MAX_SAFE_INTEGER,
    })
  );
  assert.equal(usdtOverflow.quote, null);
  assert.equal(usdtOverflow.orderSubtotalUsdtText, "—");

  const invalidUsdtBase = checkoutReview(reviewConfig({ baseUsdtPrice: 0 }));
  assert.equal(invalidUsdtBase.quote, null);
});
test("payment changes project copy, required contact, and native form validity", () => {
  let otherFieldsValid = false;
  let contactComplete = false;
  let review;
  review = checkoutReview(reviewConfig());
  review.$refs = {
    checkoutForm: {
      matches(selector) {
        assert.equal(selector, ":valid");
        return otherFieldsValid && (!review.contactRequired || contactComplete);
      },
    },
  };

  assert.equal(review.contactRequired, false);
  assert.equal(review.stickySubmitDisabled, true);

  review.selectedPaymentMethod = "binance_pay_id";
  assert.equal(review.stickyPaymentLabel, "Binance Pay ID");
  assert.equal(review.stickyAmountLabel, "Total dasar USDT / estimasi");
  assert.equal(review.stickyAmountValue, "1 USDT");
  assert.equal(review.stickySubmitLabel, "Buat invoice Binance Pay");
  assert.equal(review.stickySubmitDisabled, true);

  otherFieldsValid = true;
  review.refreshFormValidity();
  assert.equal(review.stickySubmitDisabled, false);

  review.selectedPaymentMethod = "sakurupiah";
  review.refreshFormValidity();
  assert.equal(review.contactRequired, true);
  assert.equal(
    review.contactRequirementText,
    "WhatsApp wajib untuk metode pembayaran ini"
  );
  assert.match(review.contactHelpText, /Nomor WhatsApp diperlukan/);
  assert.equal(review.stickySubmitDisabled, true);

  contactComplete = true;
  review.refreshFormValidity();
  assert.equal(review.stickySubmitDisabled, false);
});

test("payment metadata hydrates from server-rendered option attributes", () => {
  const review = checkoutReview(
    reviewConfig({ paymentOptions: [], selectedPaymentMethod: "" })
  );
  const option = {
    dataset: {
      paymentValue: "sakurupiah",
      paymentLabel: "QRIS Sakurupiah",
      paymentDescription: "Bayar melalui Sakurupiah.",
      paymentProvider: "sakurupiah",
      paymentAmountKind: "idr",
      paymentAmountLabel: "Subtotal review (IDR)",
      paymentAmountHelp: "Total final pada invoice.",
      paymentSubmitLabel: "Buat invoice Sakurupiah",
    },
  };
  const root = {
    querySelectorAll: () => [option],
    querySelector(selector) {
      if (selector === 'input[name="payment_method"]:checked') {
        return { value: "sakurupiah" };
      }
      if (selector === "[data-checkout-sticky-payment]") {
        return { textContent: "QRIS Sakurupiah" };
      }
      if (selector === "[data-checkout-sticky-amount-label]") {
        return { textContent: "Subtotal review (IDR)" };
      }
      if (selector === "[data-checkout-sticky-submit]") {
        return { textContent: "Buat invoice Sakurupiah" };
      }
      if (selector === "[data-checkout-contact-help]") {
        return { dataset: { defaultContactHelp: "Kontak opsional." } };
      }
      if (selector === "[data-checkout-contact-details]") {
        return { open: true };
      }
      return null;
    },
  };
  review.$root = root;
  review.$refs = {};
  review.$nextTick = (callback) => callback();

  review.init();

  assert.equal(review.selectedPaymentMethod, "sakurupiah");
  assert.equal(review.selectedPaymentLabel, "QRIS Sakurupiah");
  assert.equal(review.contactRequired, true);
  assert.equal(review.contactDefaultHelp, "Kontak opsional.");
  assert.equal(review.contactDisclosureOpen, true);
});

test("sticky measurement listeners and observer are released on destroy", () => {
  const originalWindow = Object.getOwnPropertyDescriptor(globalThis, "window");
  const originalResizeObserver = Object.getOwnPropertyDescriptor(
    globalThis,
    "ResizeObserver"
  );
  let addedListener;
  let removedListener;
  let observedElement;
  let disconnected = false;

  class TestResizeObserver {
    constructor(callback) {
      this.callback = callback;
    }

    observe(element) {
      observedElement = element;
    }

    disconnect() {
      disconnected = true;
    }
  }

  Object.defineProperty(globalThis, "window", {
    configurable: true,
    value: {
      matchMedia: () => ({ matches: true }),
      addEventListener: (name, listener) => {
        assert.equal(name, "resize");
        addedListener = listener;
      },
      removeEventListener: (name, listener) => {
        assert.equal(name, "resize");
        removedListener = listener;
      },
    },
  });
  Object.defineProperty(globalThis, "ResizeObserver", {
    configurable: true,
    value: TestResizeObserver,
  });

  try {
    const review = checkoutReview(reviewConfig());
    const stickyAction = { getBoundingClientRect: () => ({ height: 72 }) };
    review.$root = { querySelectorAll: () => [], querySelector: () => null };
    review.$refs = { stickyAction };
    review.$nextTick = (callback) => callback();

    review.init();
    assert.equal(observedElement, stickyAction);
    assert.equal(review.stickyBottomPadding, "72px");
    assert.equal(typeof addedListener, "function");

    review.destroy();
    assert.equal(disconnected, true);
    assert.equal(removedListener, addedListener);
  } finally {
    if (originalWindow) {
      Object.defineProperty(globalThis, "window", originalWindow);
    } else {
      delete globalThis.window;
    }
    if (originalResizeObserver) {
      Object.defineProperty(
        globalThis,
        "ResizeObserver",
        originalResizeObserver
      );
    } else {
      delete globalThis.ResizeObserver;
    }
  }
});
