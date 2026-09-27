import assert from "node:assert/strict";
import test from "node:test";
import adminDashboard from "../../resources/js/admin-dashboard.js";
import accountInputWorkflow from "../../resources/js/admin-dashboard/account-input-workflow.js";

let operationKeySequence = 0;

const validCard = (overrides = {}) => ({
  email: "buyer@example.test",
  password: "private-password",
  accessMethod: "none",
  accessValue: "",
  ...overrides,
});

const reviewAccount = (index = 0) => ({
  index,
  account_number: index + 1,
  email_masked: "b•••@example.test",
  access_method: "none",
  otp_domain: null,
  duplicate_status: "clear",
  duplicate_fields: [],
  duplicate_sources: [],
});

const reviewResponse = (payload, reviewOverrides = {}) => ({
  ok: true,
  json: async () => ({
    message: "Review server selesai.",
    review: {
      operation_key: payload.operation_key,
      status: "ready",
      account_count: payload.accounts.length,
      duplicate_count: 0,
      accounts: payload.accounts.map((_, index) => reviewAccount(index)),
      receipt_preview: { buyer_locale: payload.buyer_locale },
      ...reviewOverrides,
    },
  }),
});

const makeWorkflow = (overrides = {}) => {
  const workflow = accountInputWorkflow({
    createOperationKey: () => `account-op-${++operationKeySequence}`,
    getCsrfToken: () => "csrf-token",
    fetchRequest: async (_url, options) =>
      reviewResponse(JSON.parse(options.body)),
    ...overrides,
  });
  workflow.stockAccountsReviewAction =
    "/admin/products/1/stock/accounts/review";
  workflow.stockAccountsFormAction = "/admin/products/1/stock/accounts";
  workflow.accountCards[0] = validCard();
  workflow.$nextTick = (callback) => callback();
  return workflow;
};

const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return { promise, resolve, reject };
};

test("every draft edit invalidates a pending review revision", async () => {
  const mutations = [
    [
      "email field",
      (workflow) => {
        workflow.accountCards[0].email = "changed@example.test";
        workflow.accountInputChanged();
      },
    ],
    [
      "password field",
      (workflow) => {
        workflow.accountCards[0].password = "changed-password";
        workflow.accountInputChanged();
      },
    ],
    [
      "access value field",
      (workflow) => {
        workflow.accountCards[0].accessMethod = "auth_token";
        workflow.accountCards[0].accessValue = "changed-token";
        workflow.accountInputChanged();
      },
    ],
    ["method", (workflow) => workflow.setAccountAccessMethod(0, "auth_token")],
    ["add card", (workflow) => workflow.addAccountCard()],
    [
      "remove card",
      (workflow) => {
        workflow.addAccountCard();
        workflow.removeAccountCard(1);
      },
    ],
    [
      "locale",
      (workflow) => {
        workflow.accountInputBuyerLocale = "en";
        workflow.accountInputChanged();
      },
    ],
  ];

  for (const [name, mutate] of mutations) {
    const pending = deferred();
    let submittedPayload;
    const workflow = makeWorkflow({
      fetchRequest: (_url, options) => {
        submittedPayload = JSON.parse(options.body);
        return pending.promise;
      },
    });
    const submittedRevision = workflow.accountInputDraftRevision;
    const reviewPromise = workflow.reviewAccountInput();

    assert.equal(workflow.accountInputReviewing, true, name);
    mutate(workflow);
    assert.ok(
      workflow.accountInputDraftRevision > submittedRevision,
      `${name} advances the draft revision`
    );
    assert.equal(workflow.accountInputReviewed, false, name);
    assert.equal(workflow.accountInputReview, null, name);

    pending.resolve(reviewResponse(submittedPayload));
    assert.equal(await reviewPromise, false, name);
    assert.equal(workflow.accountInputCanCommit(), false, name);
    assert.equal(workflow.accountInputReview, null, name);
    assert.deepEqual(workflow.accountInputErrors, {}, name);
  }
});

test("stale server validation errors cannot replace changed-draft errors", async () => {
  const pending = deferred();
  const workflow = makeWorkflow({
    fetchRequest: () => pending.promise,
  });
  const reviewPromise = workflow.reviewAccountInput();

  workflow.accountCards[0].email = "still-invalid";
  workflow.accountInputChanged();
  workflow.accountInputErrors = workflow.accountInputValidationErrors();
  workflow.accountInputMessage = "Current draft state";
  const currentErrors = structuredClone(workflow.accountInputErrors);

  pending.resolve({
    ok: false,
    json: async () => ({
      message: "Old server response",
      errors: { "accounts.0.email": ["Error for the submitted draft"] },
    }),
  });

  assert.equal(await reviewPromise, false);
  assert.equal(workflow.accountInputReviewed, false);
  assert.equal(workflow.accountInputStage, "edit");
  assert.equal(workflow.accountInputReview, null);
  assert.deepEqual(workflow.accountInputErrors, currentErrors);
  assert.equal(workflow.accountInputMessage, "Current draft state");
});

test("a valid current review enables commit and exposes its safe account projection", async () => {
  const workflow = makeWorkflow();

  assert.equal(await workflow.reviewAccountInput(), true);
  assert.equal(workflow.accountInputCanCommit(), true);
  assert.equal(workflow.accountInputReview.status, "ready");
  assert.equal(workflow.accountInputReviewAccounts().length, 1);
  assert.equal(
    workflow.accountInputReviewAccounts()[0].email_masked,
    "b•••@example.test"
  );
});

test("malformed review responses fail closed", async () => {
  const workflow = makeWorkflow({
    fetchRequest: async (_url, options) =>
      reviewResponse(JSON.parse(options.body), { accounts: [] }),
  });

  assert.equal(await workflow.reviewAccountInput(), false);
  assert.equal(workflow.accountInputReviewed, false);
  assert.equal(workflow.accountInputStage, "edit");
  assert.equal(workflow.accountInputReview, null);
  assert.equal(workflow.accountInputCanCommit(), false);
  assert.match(workflow.accountInputMessage, /hasil yang valid/);
});

test("current server rejection cannot leave Account Input committable", async () => {
  const workflow = makeWorkflow({
    fetchRequest: async () => ({
      ok: false,
      json: async () => ({
        message: "Server validation rejected the draft.",
        errors: { "accounts.0.email": ["Email already exists."] },
      }),
    }),
  });

  assert.equal(await workflow.reviewAccountInput(), false);
  assert.equal(workflow.accountInputReviewed, false);
  assert.equal(workflow.accountInputStage, "edit");
  assert.equal(workflow.accountInputCanCommit(), false);
  assert.equal(workflow.accountInputError(0, "email"), "Email already exists.");
});

test("a pending Account Input commit cannot mutate a replacement draft", async () => {
  for (const succeeds of [true, false]) {
    const pending = deferred();
    let commits = 0;
    let redirects = 0;
    let requests = 0;
    const workflow = makeWorkflow({
      fetchRequest: () => {
        requests++;
        return pending.promise;
      },
    });
    workflow.accountInputReviewed = true;
    workflow.accountInputStage = "review";
    workflow.accountInputReview = { status: "ready" };
    workflow.closeStockModal = () => commits++;
    const previousWindow = globalThis.window;
    globalThis.window = { location: { assign: () => redirects++ } };

    try {
      const submit = workflow.submitAccountInput({ preventDefault() {} });
      await workflow.submitAccountInput({ preventDefault() {} });
      assert.equal(requests, 1);
      workflow.accountInputChanged();
      workflow.accountInputMessage = "Draf pengganti";
      workflow.accountInputErrors = { accounts: ["Draf pengganti invalid"] };
      pending.resolve({
        ok: succeeds,
        json: async () =>
          succeeds
            ? { item_count: 1, redirect_url: "/admin" }
            : { message: "Respons lama", errors: { accounts: ["Lama"] } },
      });
      await submit;

      assert.equal(commits, 0);
      assert.equal(redirects, 0);
      assert.equal(workflow.accountInputMessage, "Draf pengganti");
      assert.deepEqual(workflow.accountInputErrors, {
        accounts: ["Draf pengganti invalid"],
      });
    } finally {
      if (previousWindow === undefined) delete globalThis.window;
      else globalThis.window = previousWindow;
    }
  }
});

test("local validation rejects malformed credentials and account counts outside 1–20", () => {
  const workflow = makeWorkflow();
  workflow.accountCards[0] = validCard({
    email: "not-an-email",
    password: "  ",
    accessMethod: "otp_mail_link",
    accessValue: "http://user:secret@example.test/otp",
  });

  const malformedErrors = workflow.accountInputValidationErrors();
  assert.ok(malformedErrors["accounts.0.email"]);
  assert.ok(malformedErrors["accounts.0.password"]);
  assert.ok(malformedErrors["accounts.0.access_value"]);

  workflow.accountCards = Array.from({ length: 20 }, (_, index) =>
    validCard({ email: `buyer-${index}@example.test` })
  );
  assert.deepEqual(workflow.accountInputValidationErrors(), {});

  workflow.accountCards.push(validCard({ email: "buyer-21@example.test" }));
  assert.deepEqual(workflow.accountInputValidationErrors().accounts, [
    "Input Akun harus berisi 1 sampai 20 akun.",
  ]);

  workflow.accountCards = [];
  assert.deepEqual(workflow.accountInputValidationErrors().accounts, [
    "Input Akun harus berisi 1 sampai 20 akun.",
  ]);
});

test("stock modal resets advance revision before pending reviews resolve", async () => {
  const previousDocument = globalThis.document;
  const previousFetch = globalThis.fetch;
  const pending = deferred();
  let submittedPayload;
  const opener = { isConnected: true, focus() {} };
  globalThis.document = { activeElement: opener };
  globalThis.fetch = (_url, options) => {
    submittedPayload = JSON.parse(options.body);
    return pending.promise;
  };

  try {
    const dashboard = adminDashboard();
    dashboard.$refs = { stockItems: { focus() {} } };
    dashboard.$nextTick = (callback) => callback();
    const product = {
      id: 1,
      name: "Product",
      stock: 0,
      stockUrl: "/stock",
      stockAccountsUrl: "/stock/accounts",
      stockAccountsReviewUrl: "/stock/accounts/review",
      stockReturnUrl: "/admin?tab=produk",
    };

    dashboard.openStockModal(product);
    dashboard.accountCards[0] = validCard();
    const reviewPromise = dashboard.reviewAccountInput();
    const submittedRevision = dashboard.accountInputDraftRevision;

    dashboard.closeStockModal(true);
    const closedRevision = dashboard.accountInputDraftRevision;
    dashboard.openStockModal(product);
    assert.ok(closedRevision > submittedRevision);
    assert.ok(dashboard.accountInputDraftRevision > closedRevision);

    pending.resolve(reviewResponse(submittedPayload));
    assert.equal(await reviewPromise, false);
    assert.equal(dashboard.accountInputReview, null);
    assert.equal(dashboard.accountInputCanCommit(), false);
  } finally {
    if (previousDocument === undefined) {
      delete globalThis.document;
    } else {
      globalThis.document = previousDocument;
    }
    if (previousFetch === undefined) {
      delete globalThis.fetch;
    } else {
      globalThis.fetch = previousFetch;
    }
  }
});
test("pending Account Input submission keeps its stock modal and tab", async () => {
  const previousDocument = globalThis.document;
  const previousFetch = globalThis.fetch;
  const pending = deferred();
  const opener = { isConnected: true, focus() {} };
  globalThis.document = { activeElement: opener };
  globalThis.fetch = () => pending.promise;

  try {
    const dashboard = adminDashboard();
    dashboard.$refs = { stockItems: { focus() {} } };
    dashboard.$nextTick = (callback) => callback();
    dashboard.openStockModal({
      id: 1,
      name: "Product",
      stock: 0,
      stockUrl: "/stock",
      stockAccountsUrl: "/stock/accounts",
      stockAccountsReviewUrl: "/stock/accounts/review",
      stockReturnUrl: "/admin?tab=produk",
    });
    dashboard.stockInputMode = "account";
    dashboard.accountCards[0] = validCard();
    dashboard.accountInputReviewed = true;
    dashboard.accountInputStage = "review";
    dashboard.accountInputReview = { status: "ready" };

    const submit = dashboard.submitAccountInput({ preventDefault() {} });
    dashboard.closeStockModal();
    assert.equal(dashboard.stockModalOpen, true);
    assert.equal(dashboard.setStockInputMode("quick"), false);
    assert.equal(dashboard.stockInputMode, "account");

    dashboard.closeStockModal(true);
    pending.resolve({
      ok: true,
      json: async () => ({ redirect_url: "/admin" }),
    });
    await submit;
    assert.equal(dashboard.stockModalOpen, false);
  } finally {
    if (previousDocument === undefined) delete globalThis.document;
    else globalThis.document = previousDocument;
    if (previousFetch === undefined) delete globalThis.fetch;
    else globalThis.fetch = previousFetch;
  }
});

test("Product record changes clear native uploads without removing saved images", () => {
  const previousDocument = globalThis.document;
  const opener = { isConnected: true, focus() {} };
  globalThis.document = { activeElement: opener };

  try {
    const input = {
      value: "old-telegram-image.png",
      get files() {
        return this.value ? [{ name: this.value }] : [];
      },
    };
    const standardInput = { value: "old-standard-image.png" };
    const channelInput = { value: "old-channel-image.png" };
    const dashboard = adminDashboard();
    dashboard.$refs = {
      productImage: standardInput,
      telegramProductImage: input,
      telegramChannelImage: channelInput,
      productName: { focus() {} },
    };
    dashboard.$nextTick = (callback) => callback();

    dashboard.telegramProductImageFileName = "old-telegram-image.png";
    dashboard.telegramChannelImageFileName = "old-channel-image.png";
    dashboard.openProductForm();
    assert.equal(input.value, "");
    assert.equal(standardInput.value, "");
    assert.equal(channelInput.value, "");
    assert.equal(
      dashboard.telegramChannelImageFileName,
      "Tidak ada file yang dipilih"
    );
    assert.equal(dashboard.productImageFileName, "Tidak ada file yang dipilih");
    assert.equal(
      dashboard.telegramProductImageFileName,
      "Tidak ada file yang dipilih"
    );

    input.value = "selected-telegram-image.png";
    dashboard.updateTelegramProductImageFileName({ target: input });
    assert.equal(
      dashboard.telegramProductImageFileName,
      "selected-telegram-image.png"
    );

    standardInput.value = "selected-standard-image.png";
    dashboard.productImageFileName = standardInput.value;
    dashboard.openProductEditModal({
      id: 12,
      name: "Product",
      priceAmount: 1000,
      description: "Description",
      isActive: true,
      imageUrl: "/storage/product-existing.webp",
      telegramImageUrl: "/storage/telegram-existing.webp",
      updateUrl: "/admin/products/12",
      telegramChannelImageUrl: "/storage/channel-existing.webp",
    });

    assert.equal(input.value, "");
    assert.equal(standardInput.value, "");
    assert.equal(channelInput.value, "");
    assert.equal(
      dashboard.telegramChannelImageFileName,
      "Tidak ada file yang dipilih"
    );
    assert.equal(dashboard.productImageFileName, "Tidak ada file yang dipilih");
    assert.equal(
      dashboard.telegramProductImageFileName,
      "Tidak ada file yang dipilih"
    );
    assert.equal(dashboard.productImageUrl, "/storage/product-existing.webp");
    assert.equal(
      dashboard.telegramProductImageUrl,
      "/storage/telegram-existing.webp"
    );
    assert.equal(
      dashboard.telegramChannelImageUrl,
      "/storage/channel-existing.webp"
    );
  } finally {
    if (previousDocument === undefined) {
      delete globalThis.document;
    } else {
      globalThis.document = previousDocument;
    }
  }
});

test("Family record changes clear both native image selections", () => {
  const previousDocument = globalThis.document;
  globalThis.document = { activeElement: { isConnected: true, focus() {} } };

  try {
    const image = { value: "stale-family.png" };
    const telegramImage = { value: "stale-telegram-family.png" };
    const dashboard = adminDashboard();
    dashboard.$refs = {
      familyImage: image,
      telegramFamilyImage: telegramImage,
      familyName: { focus() {} },
    };
    dashboard.$nextTick = (callback) => callback();

    dashboard.openProductFamilyForm();
    assert.equal(image.value, "");
    assert.equal(telegramImage.value, "");

    image.value = "different-family.png";
    telegramImage.value = "different-telegram-family.png";
    dashboard.openProductFamilyEditModal({
      id: 12,
      name: "Family",
      imageUrl: "/storage/family-existing.webp",
      telegramImageUrl: "/storage/telegram-existing.webp",
      updateUrl: "/admin/product-families/12",
    });
    assert.equal(image.value, "");
    assert.equal(telegramImage.value, "");
    assert.equal(dashboard.familyImageFileName, "Tidak ada file yang dipilih");
    assert.equal(
      dashboard.telegramFamilyImageFileName,
      "Tidak ada file yang dipilih"
    );
    assert.equal(dashboard.familyImageUrl, "/storage/family-existing.webp");
    assert.equal(
      dashboard.telegramFamilyImageUrl,
      "/storage/telegram-existing.webp"
    );

    image.value = "unsubmitted.png";
    telegramImage.value = "unsubmitted-telegram.png";
    dashboard.resetProductFamilyForm();
    assert.equal(image.value, "");
    assert.equal(telegramImage.value, "");
  } finally {
    if (previousDocument === undefined) delete globalThis.document;
    else globalThis.document = previousDocument;
  }
});

test("Family editing retains the visible manager opener and direct opener", () => {
  const previousDocument = globalThis.document;
  const focusCalls = [];
  const managerOpener = {
    isConnected: true,
    focus() {
      focusCalls.push("manager");
    },
  };
  const nestedEditButton = { isConnected: false, focus() {} };
  const directOpener = {
    isConnected: true,
    focus() {
      focusCalls.push("direct");
    },
  };
  globalThis.document = { activeElement: managerOpener };

  try {
    const dashboard = adminDashboard();
    dashboard.$refs = { familyName: { focus() {} } };
    dashboard.$nextTick = (callback) => callback();

    dashboard.openProductFamilyForm();
    globalThis.document.activeElement = nestedEditButton;
    dashboard.openProductFamilyEditModal({
      id: 3,
      name: "Family",
      updateUrl: "/admin/product-families/3",
    });
    assert.strictEqual(dashboard.familyModalOpener, managerOpener);

    dashboard.closeProductFamilyModal();
    assert.deepEqual(focusCalls, ["manager"]);

    globalThis.document.activeElement = directOpener;
    dashboard.openProductFamilyEditModal({
      id: 4,
      name: "Direct family",
      updateUrl: "/admin/product-families/4",
    });
    assert.strictEqual(dashboard.familyModalOpener, directOpener);

    dashboard.closeProductFamilyModal();
    assert.deepEqual(focusCalls, ["manager", "direct"]);
  } finally {
    if (previousDocument === undefined) {
      delete globalThis.document;
    } else {
      globalThis.document = previousDocument;
    }
  }
});
